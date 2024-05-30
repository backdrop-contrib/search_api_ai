<?php

namespace Drupal\search_api_ai_pgvector\Plugin\search_api\backend;

use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Database\DatabaseException;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Plugin\PluginFormTrait;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api_ai\SearchApiAiBackendInterface;
use Drupal\search_api_ai\Trait\SearchApiAiBackendTrait;
use Drupal\search_api_db\Plugin\search_api\backend\Database;

/**
 * PG Vector backend for search api.
 *
 * @SearchApiBackend(
 *   id = "search_api_ai_pgvector",
 *   label = @Translation("PG Vector"),
 *   description = @Translation("Index items on PG Vector.")
 * )
 */
class SearchApiPgVectorBackend extends Database implements PluginFormInterface, SearchApiAiBackendInterface {

  use PluginFormTrait;
  use SearchApiAiBackendTrait;

  /**
   * DB Prefix.
   */
  const DBPREFIX = 'search_vector_';

  /**
   * Constructs a new SearchApiPgVectorBackend object.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->setEngineConfiguration($configuration);
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return parent::defaultConfiguration() + $this->defaultEngineConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable() {
    // Check so the database is Postgresql specifically.
    return $this->getDatabase()->databaseType() === 'pgsql';
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    // Set configuration and run engine configuration form and merge.
    $this->setConfiguration($this->configuration);
    $engineForm = $this->engineConfigurationForm($form, $form_state);
    $form = array_merge($form, $engineForm);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    // Check so the database is Postgresql specifically.
    if ($this->getDatabase()->databaseType() !== 'pgsql') {
      $form_state->setError($form['embeddings_engine_configuration'], $this->t('The database must be Postgresql.'));
    }
  }

  /**
   * Updates the storage tables when the field configuration changes.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The search index whose fields (might) have changed.
   *
   * @return bool
   *   TRUE if the data needs to be reindexed, FALSE otherwise.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   Thrown if any exceptions occur internally – for example, in the database
   *   layer.
   */
  protected function fieldsUpdated(IndexInterface $index) {
    $needed = parent::fieldsUpdated($index);
    // Check if one of the fields is a vector field.
    foreach ($index->getFields() as $field) {
      if ($field->getType() === 'embeddings') {
        // Generate a table for the index.
        $index_table = self::DBPREFIX . $index->id();
        $generated = $this->createVectorTable($index_table);
        if ($generated) {
          $needed = TRUE;
        }
      }
    }

    return $needed;
  }

  /**
   * Creates or modifies a table to add a vector field.
   *
   * @param string $table_name
   *   The table to generate if needed.
   *
   * @return bool
   *   TRUE if the table was created or modified, FALSE if it already existed.
   */
  protected function createVectorTable(string $table_name): bool {
    // If table exists, we don't need to do anything.
    $new_table = !$this->database->schema()->tableExists($table_name);
    // Check the current size of the vector, if we should change.
    if ($this->getVectorDimensions() != $this->configuration['embeddings_engine_configuration']['dimension']) {
      $new_table = TRUE;
      // Drop table and rebuild.
      $this->database->schema()->dropTable($table_name);
    }
    if (!$new_table) {
      return FALSE;
    }

    if ($new_table) {
      $table = [
        'name' => $table_name,
        'module' => 'search_api_ai_pgvector',
        'fields' => [
          'item_id' => [
            'type' => 'varchar',
            'length' => 150,
            'description' => 'The primary identifier of the item',
            'not null' => TRUE,
          ],
          'entity_id' => [
            'type' => 'varchar',
            'length' => 150,
            'description' => 'The entity identifier of the item',
            'not null' => TRUE,
          ],
          'content' => [
            'type' => 'text',
            'size' => 'big',
            'description' => 'The content of the item',
            'not null' => TRUE,
          ],
        ],
        'primary key' => ['item_id'],
        'indexes' => [
          'entity_id' => ['entity_id'],
        ],
      ];

      $this->database->schema()->createTable($table_name, $table);
      $this->dbmsCompatibility->alterNewTable($table_name, 'index');
    }

    // Enable the extension.
    $this->database->query('CREATE EXTENSION IF NOT EXISTS vector;');
    // Add field to the table, because of special solution, abstraction will
    // not work. Only allow int and $table was already verified variable.
    $this->database->query('ALTER TABLE {' . $table_name . '} ADD COLUMN vectors vector(' . (int) $this->configuration['embeddings_engine_configuration']['dimension'] . ');');

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  protected function indexItem(IndexInterface $index, ItemInterface $item) {
    // Delete all vectors for this item.
    $this->deleteItems($index, [$item->getId()]);
    // Run the index of the item after deletion.
    parent::indexItem($index, $item);
    // Get the fields.
    $fields = $item->getFields();

    // Then start adding new vectors.
    $chunkedItems = [];
    foreach ($fields as $field) {
      if ($field->getType() === 'embeddings') {
        foreach ($field->getValues() as $delta1 => $values) {
          foreach ($values as $delta2 => $value) {
            $chunkedItems[] = [
              'drupal_long_id' => $item->getId() . ':' . $field->getFieldIdentifier() . ':' . $delta1 . ':' . $delta2,
              'drupal_entity_id' => $item->getId(),
              'vectors' => $value['vectors'],
              'content' => $value['content']
            ];
          }
        }
      }
    }
    // Store the vectors in the database.
    foreach ($chunkedItems as $chunkedItem) {
      $this->database->merge(self::DBPREFIX . $index->id())
        ->key(['item_id' => $chunkedItem['drupal_long_id']])
        ->fields([
          'entity_id' => $chunkedItem['drupal_entity_id'],
          'vectors' => '[' . implode(',', $chunkedItem['vectors']) .']',
          'content' => $chunkedItem['content'],
        ])
        ->execute();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function convert($value, $type, $original_type, IndexInterface $index) {
    // We make sure that embeddings are not indexed, by normal means.
    if ($type === 'embeddings') {
      return [];
    }
    return parent::convert($value, $type, $original_type, $index);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteItems(IndexInterface $index, array $item_ids) {
    parent::deleteItems($index, $item_ids);
    // Delete on items ids.
    $this->database->delete(self::DBPREFIX . $index->id())
      ->condition('entity_id', $item_ids, 'IN')
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAllIndexItems(IndexInterface $index, $datasource_id = NULL) {
    parent::deleteAllIndexItems($index, $datasource_id);
    // Delete everything in the index.
    $this->database->delete(self::DBPREFIX . $index->id())
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function search(QueryInterface $query) {
    // See \Drupal\search_api_db\Plugin\search_api\backend\Database::search().
    // We need to copy the code here to be able to add the vectors to the query.
    // :(.
    $this->ignored = $this->warnings = [];
    $index = $query->getIndex();
    $db_info = $this->getIndexDbInfo($index);

    if (!isset($db_info['field_tables'])) {
      $index_id = $index->id();
      throw new SearchApiException("No field settings saved for index with ID '$index_id'.");
    }
    $fields = $this->getFieldInfo($index);
    $fields['search_api_id'] = [
      'column' => 'item_id',
    ];

    $db_query = $this->createDbQuery($query, $fields);

    $results = $query->getResults();

    try {
      $skip_count = $query->getOption('skip result count');
      $count = NULL;
      if (!$skip_count) {
        $count_query = $db_query->countQuery();
        $count = $count_query->execute()->fetchField();
        $results->setResultCount($count);
      }

      // With a "min_count" of 0, some facets can even be available if there are
      // no results.
      if ($query->getOption('search_api_facets')) {
        $facets = $this->getFacets($query, clone $db_query, $count);
        $results->setExtraData('search_api_facets', $facets);
      }

      // Everything else can be skipped if the count is 0.
      if ($skip_count || $count) {
        $query_options = $query->getOptions();
        if (isset($query_options['offset']) || isset($query_options['limit'])) {
          $offset = $query_options['offset'] ?? 0;
          $limit = $query_options['limit'] ?? 1000000;
          $db_query->range($offset, $limit);
        }

        $this->setQuerySort($query, $db_query, $fields);

        $result = $db_query->execute();

        $indexed_fields = $index->getFields(TRUE);
        $retrieved_field_names = $query->getOption('search_api_retrieved_field_values', []);
        foreach ($result as $row) {
          $item = $this->getFieldsHelper()->createItem($index, $row->drupal_long_id);
          $item->setScore($row->score / self::SCORE_MULTIPLIER);
          $this->extractRetrievedFieldValuesWhereAvailable($row, $indexed_fields, $retrieved_field_names, $item);
          // If its a vector search, we need to add the vectors to the item.
          // This bit inside conditional is different from the parent class.
          if ($query->getOption('query_embedding')) {
            // We do cosine distance and want cosine similarity.
            $item->setScore((1 - $row->vector_score));
            $item->setExtraData('content', $row->content);
            $item->setExtraData('drupal_entity_id', $row->drupal_entity_id);
          }
          $results->addResultItem($item);
        }
        if ($skip_count && !empty($item)) {
          $results->setResultCount(1);
        }
      }
    } catch (\PDOException | DatabaseException $e) {
      if ($query instanceof RefinableCacheableDependencyInterface) {
        $query->mergeCacheMaxAge(0);
      }
      throw new SearchApiException('A database exception occurred while searching.', $e->getCode(), $e);
    }

    // Add additional warnings and ignored keys.
    $metadata = [
      'warnings' => 'addWarning',
      'ignored' => 'addIgnoredSearchKey',
    ];
    foreach ($metadata as $property => $method) {
      foreach (array_keys($this->$property) as $value) {
        $results->$method($value);
      }
    }
  }

  /**
   * Check dimensions of the vectors.
   *
   * @return int
   *   The number of dimensions.
   */
  protected function getVectorDimensions(): int {
    $query = $this->database->query("SELECT
    a.attname AS column_name,
    t.typname AS data_type,
    CASE
        WHEN t.typname = 'vector' THEN
            substring(format_type(a.atttypid, a.atttypmod) FROM '\((\d+)\)')::int
        ELSE NULL
    END AS dimensions
FROM
    pg_attribute a
JOIN
    pg_class c ON a.attrelid = c.oid
JOIN
    pg_type t ON a.atttypid = t.oid
WHERE
    c.relname = 'search_vector_articles' -- Your table name
    AND a.attname = 'vectors' -- Your vector column name
    AND a.attnum > 0
    AND NOT a.attisdropped");

    return $query->fetchField(2);
  }

  /**
   * {@inheritdoc}
   */
  protected function createDbQuery(QueryInterface $query, array $fields) {
    $db_query = parent::createDbQuery($query, $fields);
    // If a query embedding is requested, we need to add the vectors to the
    // query.
    if ($query->getOption('query_embedding')) {
      // Join the vectors table.
      $db_query->addJoin('INNER', self::DBPREFIX . $query->getIndex()->id(), 'vec', 'vec.entity_id = t.item_id');
      // Add the field for the vectors content + other fields.
      $db_query->addField('vec', 'content', 'content');
      $db_query->addField('vec', 'entity_id', 'drupal_entity_id');
      $db_query->addField('vec', 'item_id', 'drupal_long_id');
      // Make a filter for the vectors, use cosine by default.
      $db_query->addExpression('vec.vectors <=> :vectors', 'vector_score', [
        ':vectors' => '[' . implode(',', $query->getOption('query_embedding')) . ']',
      ]);
      // Sort by the vectors.
      $db_query->orderBy('vector_score', 'ASC');
    }

    return $db_query;
  }

  /**
   * {@inheritdoc}
   */
  public function supportsDataType($type): bool {
    // Support the embeddings data type hardcoded.
    if ($type === 'embeddings') {
      return TRUE;
    }
    return parent::supportsDataType($type);
  }

}

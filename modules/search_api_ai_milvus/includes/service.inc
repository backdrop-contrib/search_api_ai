<?php
/**
 * @file
 * Milvus Search API backend for Backdrop CMS.
 */

require_once backdrop_get_path('module', 'search_api_ai') . '/includes/SearchApiAiBackendPluginBase.inc';
require_once backdrop_get_path('module', 'search_api_ai') . '/includes/SearchApiBackendInterface.inc';
require_once backdrop_get_path('module', 'search_api_ai') . '/includes/SearchApiAiBackend.inc';

class MilvusService extends SearchApiAbstractService implements SearchApiAiBackendInterface {

  /**
   * @var SearchApiAiMilvusVectorClient
   */
  protected $client;

  protected $configuration;

  public function __construct($configuration = []) {
    parent::__construct($configuration);

    // Ensure we have the options array that was saved on the server config.
    // parent::__construct() should populate $this->options.
    // Keep $this->configuration around if you want, but build the client from $this->options.
    if (is_object($configuration) && property_exists($configuration, 'options')) {
      $this->configuration = (array) $configuration->options;
    } elseif (is_array($configuration)) {
      $this->configuration = $configuration;
    } else {
      $this->configuration = [];
    }

    // Make sure the class file matches your autoload entry (.php here).
    require_once __DIR__ . '/SearchApiAiMilvusVectorClient.php';

    // Map both new and legacy keys into what the client expects.
    // The client’s normalizeSettings() already accepts these, but we pass them explicitly
    // from $this->options (the canonical source).
    $settings = [
      // exactly what your config form saves:
      'basehost'     => $this->options['basehost']    ?? '',
      'port'         => $this->options['port']        ?? '443',
      'api_key'      => $this->options['api_key']     ?? '',
      'database'     => $this->options['database']    ?? 'default',
      'collection'   => $this->options['collection']  ?? '',
      'metric_type'  => $this->options['metric_type'] ?? 'COSINE',

      // keep acceptance of alternates if they exist:
      'server'        => $this->options['server']        ?? NULL,
      'milvus_server' => $this->options['milvus_server'] ?? NULL,
      'milvus_port'   => $this->options['milvus_port']   ?? NULL,
      'milvus_token'  => $this->options['milvus_token']  ?? NULL,
    ];
    $this->client = new SearchApiAiMilvusVectorClient($settings);

  }



  /**
   * Provides default configuration.
   */
  public function defaultConfiguration() {
    return parent::defaultConfiguration() + [
        'basehost'    => '',
        'api_key'     => '',
        'port'        => '443',
        'database'    => 'default',
        'collection'  => '',
        'metric_type' => 'COSINE',
      ];
  }

  /**
   * Returns the configuration form.
   */
  public function configurationForm($form, &$form_state) {
    // Add embedding engine fields from search_api_ai.
    $form = search_api_ai_update_embedding_configuration_form($form, $form_state);
    $form['basehost'] = [
      '#type' => 'textfield',
      '#title' => t('Server'),
      '#required' => TRUE,
      '#description' => t('The server to connect to. If you use Zilliz Cloud, this can be found under Public Endpoint.'),
      '#default_value' => !empty($this->options['basehost']) ? $this->options['basehost'] : '',
    ];
    $form['port'] = [
      '#type' => 'textfield',
      '#title' => t('Port'),
      '#required' => TRUE,
      '#description' => t('The server port to connect to. If you use Zilliz Cloud, this is 443.'),
      '#default_value' => !empty($this->options['port']) ? $this->options['port'] : '443',
    ];
    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => t('API Key'),
      '#description' => t('The API key to use for authentication.'),
      '#default_value' => !empty($this->options['api_key']) ? $this->options['api_key'] : '',
    ];
    $form['database'] = [
      '#type' => 'textfield',
      '#title' => t('Database'),
      '#required' => TRUE,
      '#description' => t('The database to connect to.'),
      '#default_value' => !empty($this->options['database']) ? $this->options['database'] : 'default',
    ];
    $form['collection'] = [
      '#type' => 'textfield',
      '#title' => t('Collection'),
      '#description' => t('The collection to use. This will be generated if it does not exist and cannot be changed.'),
      '#default_value' => !empty($this->options['collection']) ? $this->options['collection'] : '',
      '#required' => TRUE,
      '#pattern' => '[a-zA-Z0-9_]*',
    ];
    $form['metric_type'] = [
      '#type' => 'select',
      '#title' => t('Metric Type'),
      '#description' => t('The metric type to use for the collection.'),
      '#default_value' => !empty($this->options['metric_type']) ? $this->options['metric_type'] : 'COSINE',
      '#required' => TRUE,
      '#options' => [
        'L2' => 'L2',
        'IP' => 'IP',
        'COSINE' => 'COSINE',
      ],
    ];
    return $form;
  }

  /**
   * Indexes items in Milvus.
   */
  public function indexItems(SearchApiIndex $index, array $items) {
    global $search_api_ai_is_indexing;
    $search_api_ai_is_indexing = TRUE;

    $serverId = $this->server->id();
    $indexId  = $index->id();

    // Collect which fields on this index are llm vectors.
    $vector_field_names = [];
    if (!empty($index->options['fields'])) {
      foreach ($index->options['fields'] as $field_name => $field_info) {
        if (!empty($field_info['real_type']) && $field_info['real_type'] === 'llm_vector_embedding') {
          $vector_field_names[] = $field_name;
        }
      }
    }

    if (empty($items)) {
      watchdog('search_api_ai_milvus', '❌ No items to index. Exiting.', [], WATCHDOG_WARNING);
      return [];
    }

    // Dimension from index settings first; fallback to global helper.
    $expected_dimension = isset($index->options['embedding_settings']['embeddings_engine_configuration']['dimension'])
      ? (int) $index->options['embedding_settings']['embeddings_engine_configuration']['dimension']
      : search_api_ai_get_embedding_dimension();

    $collection = $this->options['collection'];
    $database   = $this->options['database'];

    $successful_item_ids = [];
    $batch_vectors = [];

    // Build a map of entity_type => [ids] from $items.
    $item_ids_to_index = [];
    foreach ($items as $item) {
      if (is_array($item)) $item = (object) $item;

      if (isset($item->nid)) {
        $item_ids_to_index['node'][] = is_array($item->nid) && isset($item->nid['value']) ? (int) $item->nid['value'] : (int) $item->nid;
      }
      elseif (isset($item->tid)) {
        $item_ids_to_index['taxonomy_term'][] = is_array($item->tid) && isset($item->tid['value']) ? (int) $item->tid['value'] : (int) $item->tid;
      }
      elseif (isset($item->item_id)) {
        $item_ids_to_index['paragraphs_item'][] = is_array($item->item_id) && isset($item->item_id['value']) ? (int) $item->item_id['value'] : (int) $item->item_id;
      }
    }

    // For each entity to index, normalize vectors (handles flat + legacy).
    foreach ($item_ids_to_index as $entity_type => $ids) {
      foreach (array_unique($ids) as $entity_id) {
        $rows = search_api_ai_normalize_vectors($entity_type, $entity_id, $expected_dimension);

        if (empty($rows)) {
          watchdog('search_api_ai_milvus', "No vectors for @type/@id.", ['@type' => $entity_type, '@id' => $entity_id], WATCHDOG_WARNING);
          continue;
        }

        foreach ($rows as $r) {
          // Only index fields that are configured as llm_vector_embedding on this index.
          if (!in_array($r['field_name'], $vector_field_names, TRUE)) {
            continue;
          }

          // Compose a stable ID per chunk (matches Pinecone convention).
          $milvus_id = "entity:{$entity_type}/{$entity_id}:en:{$r['field_name']}:{$r['chunk_index']}";

          // Base metadata that Milvus filters/search can use.
          $metadata = array_merge([
            'content'            => $r['metadata']['content'] ?? '',
            'title'              => $r['metadata']['title'] ?? NULL,
            'search_api_url'     => $r['metadata']['search_api_url'] ?? NULL,
            'url'                => $r['metadata']['url'] ?? NULL,

            'backdrop_entity_id' => "entity:{$entity_type}/{$entity_id}:en",
            'backdrop_long_id'   => $milvus_id,
            'server_id'          => $serverId,
            'index_id'           => $indexId,
            'field_name'         => $r['field_name'],
            'chunk_index'        => $r['chunk_index'],

            // Convenience fields for filters:
            'nid'                => ($entity_type === 'node') ? $entity_id : NULL,
            'tid'                => ($entity_type === 'taxonomy_term') ? $entity_id : NULL,
            'pid'                => ($entity_type === 'paragraphs_item') ? $entity_id : NULL,
          ], []); // merge hook point

          // Add to batch.
          $batch_vectors[] = [
            'id'       => $milvus_id,
            'values'   => $r['values'], // already floatval + correct order from normalizer
            'metadata' => $metadata,
          ];

          $successful_item_ids[] = $entity_id;
        }
      }
    }

    if (!empty($batch_vectors)) {
      try {
        $this->client->upsert([
          'collection' => $collection,
          'database'   => $database,
          'vectors'    => $batch_vectors,
        ]);
        watchdog('search_api_ai_milvus', 'Indexed @count vectors into Milvus.', ['@count' => count($batch_vectors)], WATCHDOG_NOTICE);
      }
      catch (Exception $e) {
        watchdog('search_api_ai_milvus', '❌ Milvus indexing error: @message', ['@message' => $e->getMessage()], WATCHDOG_ERROR);
      }
    }

    return array_values(array_unique($successful_item_ids));
  }


  /**
   * Deletes indexed items from Milvus.
   */
  public function deleteItems($ids = 'all', SearchApiIndex $index = NULL) {
    global $search_api_ai_is_indexing;
    if (!empty($search_api_ai_is_indexing)) {
      watchdog('search_api_ai_milvus', 'deleteItems() during indexing; abort.', [], WATCHDOG_WARNING);
      return;
    }

    $collection = $this->options['collection'] ?? '';
    $database   = $this->options['database']   ?? NULL;

    if (empty($collection)) {
      watchdog('search_api_ai_milvus', 'Missing Milvus collection for deleteItems.', [], WATCHDOG_ERROR);
      return;
    }

    try {
      if ($ids === 'all') {
        // Delete everything in this collection (or add an index/namespace clause if needed).
        $this->client->delete([
          'collection' => $collection,
          'database'   => $database,
          'deleteAll'  => TRUE,
        ]);
        watchdog('search_api_ai_milvus', 'DeleteAll sent for collection @c.', ['@c' => $collection], WATCHDOG_NOTICE);
        return;
      }

      if (!is_array($ids) || empty($ids)) {
        watchdog('search_api_ai_milvus', 'No valid IDs for deletion.', [], WATCHDOG_ERROR);
        return;
      }

      // Build OR-of-equalities on backdrop_entity_id.
      // Each $nid deletes all chunks/fields for that entity:
      // backdrop_entity_id == "entity:node/123:en"
      $exprParts = [];
      foreach ($ids as $id) {
        $id = (int)$id;
        $exprParts[] = sprintf('backdrop_entity_id == "entity:node/%d:en"', $id);
      }
      $expr = '(' . implode(' or ', $exprParts) . ')';

      $this->client->delete([
        'collection' => $collection,
        'database'   => $database,
        'filter'     => ['expr' => $expr],
      ]);

      watchdog('search_api_ai_milvus', 'Delete by filter sent for @count id(s).', ['@count' => count($ids)], WATCHDOG_NOTICE);
    }
    catch (\Exception $e) {
      watchdog('search_api_ai_milvus', 'Delete error: @m', ['@m' => $e->getMessage()], WATCHDOG_ERROR);
    }
  }


  /**
   * Performs a search in Milvus.
   */
  public function search(SearchApiQueryInterface $query) {
    $collection = $this->options['collection'];
    $database = $this->options['database'];
    $index = $query->getIndex();
    $index_options = isset($index->options) ? $index->options : [];

    // Model selection priority: index → service options.
    $model = !empty($index_options['embedding_settings']['embeddings_engine'])
      ? $index_options['embedding_settings']['embeddings_engine']
      : (!empty($this->options['embedding_model']) ? $this->options['embedding_model'] : NULL);

    if (empty($model)) {
      watchdog(
        'search_api_ai_milvus',
        'No model configured!',
        [],
        WATCHDOG_ERROR
      );
      return ['result count' => 0, 'results' => []];
    }

    // Extract search text.
    $keys = $query->getKeys();
    $text = is_array($keys)
      ? implode(' ', array_filter($keys, function($key) {
        return is_string($key) && $key !== 'AND' && $key !== '#conjunction';
      }))
      : (string) $keys;
    $text = trim($text);

    if (empty($text)) {
      return ['result count' => 0, 'results' => []];
    }

    // Get query embedding using the appropriate provider.
    $stripped_model = NULL;
    if (function_exists('openai_get_api_for_model')) {
      $api = openai_get_api_for_model($model, $stripped_model);
      $model_to_use = !empty($stripped_model) ? $stripped_model : $model;
      watchdog('search_api_ai_milvus', 'Search embedding: model=@model, stripped=@stripped, text_len=@len, api_class=@class', [
        '@model' => $model,
        '@stripped' => $stripped_model,
        '@len' => strlen($text),
        '@class' => get_class($api),
      ], WATCHDOG_NOTICE);
    }
    else {
      // Fallback to OpenAI API directly if function doesn't exist
      $openai_config = config('openai.settings');
      $api_key = key_get_key_value($openai_config->get('api_key'));
      $api = new OpenAIApi($api_key);
      $model_to_use = $model;
    }
    if (!$api) {
      throw new Exception('Failed to get API client for model: ' . $model);
    }
    $query_embedding = $api->embedding($text, $model_to_use);

    if (empty($query_embedding)) {
      throw new Exception('Failed to retrieve embedding.');
    }

    $top_k = $query->getOption('top_k') ?? 8;
    $outputFields = [
      'content',
      'entity_id',
      'entity_type',
      'field_name',
      'field_delta',
      'bundle',
      'backdrop_entity_id',
      'backdrop_long_id',
      'nid'
    ];

    // Actual search
    try {
      $response = $this->client->query([
        'vector'           => $query_embedding,
        'top_k'            => $top_k,
        'collection'       => $collection,
        'database'         => $database,
        'include_metadata' => TRUE,
        'output_fields'    => $outputFields,
      ]);

      $results = [];
      foreach ($response as $match) {
        $entity_type = NULL;
        $entity_id = NULL;
        if (isset($match['backdrop_entity_id'])
          && preg_match(
            '/^entity:([^\/]+)\/(\d+)/',
            $match['backdrop_entity_id'],
            $m
          )
        ) {
          $entity_type = $m[1];
          $entity_id = $m[2];
        }
        elseif (isset($match['nid'])) {
          $entity_type = 'node';
          $entity_id = $match['nid'];
        }
        if ($entity_type && $entity_id) {
          $item_id = $entity_type == $query->getIndex()->getEntityType()
            ? $entity_id : "{$entity_type}/{$entity_id}";
          $results[$item_id] = [
            'id'      => $item_id,
            'score'   => $match['distance'],
            'fields'  => [],
            'entity'  => entity_load($entity_type, $entity_id),
            'excerpt' => isset($match['content']) ? strip_tags(
              $match['content']
            ) : '',
          ];
        }
      }
      return [
        'result count' => count($results),
        'results'      => $results,
      ];
    }
    catch (Exception $e) {
      watchdog('search_api_ai_milvus', '❌ Milvus query error: @message', [
        '@message' => $e->getMessage(),
      ], WATCHDOG_ERROR);
      return ['result count' => 0, 'results' => []];
    }
  }

  public function getCollection($index) {
    $collection = !empty($this->options['collection'])
      ? $this->options['collection'] : $index->machine_name;
    return preg_replace('/[^a-zA-Z0-9_]/', '_', $collection);
  }

  /**
   * Returns supported data types.
   */
  public function supportsDataType($type) {
    return in_array($type, ['embeddings']);
  }

  // Implement required interface methods for embedding engine.
  public function loadEmbeddingsEngine() {
    return search_api_ai_load_embeddings_engine($this->configuration);
  }

  public function getEmbeddingsEngine() {
    return isset($this->configuration['embeddings_engine']) ? $this->configuration['embeddings_engine'] : '';
  }

  public function getEmbeddingEnginesOptions() {
    return search_api_ai_get_embedding_engines_options();
  }

  public function updateEmbeddingConfigurationForm($form, &$form_state) {
    return search_api_ai_update_embedding_configuration_form($form, $form_state);
  }
}

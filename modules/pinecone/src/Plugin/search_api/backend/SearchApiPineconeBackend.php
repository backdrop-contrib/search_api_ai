<?php

namespace Drupal\search_api_pinecone\Plugin\search_api\backend;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\openai_embeddings\Http\PineconeClient;
use Drupal\search_api\Backend\BackendPluginBase;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Plugin\PluginFormTrait;
use Drupal\search_api\Query\QueryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Pinecone backend for search api.
 *
 * @SearchApiBackend(
 *   id = "search_api_pinecone",
 *   label = @Translation("Pinecone"),
 *   description = @Translation("Index items on Pinecone.")
 * )
 */
class SearchApiPineconeBackend extends BackendPluginBase implements PluginFormInterface {

  use PluginFormTrait;

  /**
   * The Pinecone client.
   *
   * @var \Drupal\openai_embeddings\Http\PineconeClient
   */
  protected PineconeClient $client;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'namespace' => NULL,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['namespace'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Namespace'),
      '#description' => $this->t('An optional override for the default namespace. The index ID will always be appended.'),
      '#default_value' => $this->configuration['namespace'],
      '#pattern' => '[a-zA-Z0-9_]*',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $plugin = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $plugin->client = $container->get('openai_embeddings.pinecone_client');
    return $plugin;
  }

  /**
   * {@inheritdoc}
   */
  public function indexItems(IndexInterface $index, array $items) {
    $serverId = $this->server->id();
    $indexId = $index->id();
    $namespace = $this->getNamespace($index);
    $successfulItemIds = [];

    /** @var \Drupal\search_api\Item\ItemInterface $item */
    foreach ($items as $item) {
      // Delete the item as we are actually inserting multiple.
      $this->deleteItems($index, [$item->getId()]);

      $itemBase = [
        'metadata' => [
          'server_id' => $serverId,
          'index_id' => $indexId,
          'item_id' => $item->getId(),
        ],
      ];
      $chunkedItems = [];

      // Loop over our fields and assign to the appropriate place.
      foreach ($item->getFields() as $field) {
        if ($field->getType() === 'embeddings') {
          foreach ($field->getValues() as $delta1 => $values) {
            foreach ($values as $delta2 => $value) {
              $chunkedItems[] = [
                'id' => $item->getId() . ':' . $field->getFieldIdentifier() . ':' . $delta1 . ':' . $delta2,
                'values' => $value['vectors'],
                'metadata' => [
                  'content' => $value['content'],
                ],
              ];
            }
          }
        }
        else {
          $itemBase['metadata'][$field->getFieldIdentifier()] = is_array($field->getValues()) ? implode(',', $field->getValues()) : $field->getValues();
        }
      }

      foreach ($chunkedItems as $chunkedItem) {
        $chunkedItem += $itemBase;
        $chunkedItem['metadata'] += $itemBase['metadata'];
        $this->client->upsert($chunkedItem, $namespace);
      }
      $successfulItemIds[] = $item->getId();
    }

    return $successfulItemIds;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteItems(IndexInterface $index, array $item_ids) {
    $this->client->delete(
      namespace: $this->getNamespace($index),
      filter: [
        'item_id' => ['$in' => $item_ids],
      ],
    );
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAllIndexItems(IndexInterface $index, $datasource_id = NULL) {
    $this->client->delete(
      deleteAll: TRUE,
      namespace: $this->getNamespace($index),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function search(QueryInterface $query) {
    $index = $query->getIndex();

    if ($query->hasTag('server_index_status')) {
      return NULL;
    }
    $response = $this->client->query(
      $query->getOption('query_embedding'),
      $query->getOption('top_k'),
      $query->getOption('include_metadata'),
      $query->getOption('include_values'),
      $query->getOption('filters'),
      $query->getOption('namespace')
    );
    $results = $query->getResults();

    $decoded_response = json_decode($response->getBody()->getContents(), flags: \JSON_THROW_ON_ERROR);
    foreach ($decoded_response->matches as $match) {
      $item = $this->getFieldsHelper()->createItem($index, $match->id);
      $item->setScore($match->score);
      $this->extractMetadata($match, $item);
      $results->addResultItem($item);
    }
    $results->setResultCount(count($decoded_response->matches));
  }

  /**
   * Extract query metadata values to a result item.
   */
  public function extractMetadata(object $result_row, ItemInterface $item): void {
    $item->setExtraData('metadata', $result_row->metadata);
  }

  /**
   * Get the pinecone namespace for an index.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The index.
   *
   * @return string
   *   The pinecone namespace.
   */
  public function getNamespace(IndexInterface $index): string {
    return ($this->configuration['namespace'] ?? "searchapi:{$this->server->id()}") . ":{$index->id()}";
  }

  /**
   * {@inheritdoc}
   */
  public function supportsDataType($type) {
    return in_array($type, [
      'embeddings',
    ]);
  }

}

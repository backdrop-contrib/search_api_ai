<?php

namespace Drupal\search_api_pinecone\Plugin\search_api\backend;

use Drupal\openai_embeddings\Http\PineconeClient;
use Drupal\search_api\Backend\BackendPluginBase;
use Drupal\search_api\IndexInterface;
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
class SearchApiPineconeBackend extends BackendPluginBase {

  /**
   * The Pinecone client.
   *
   * @var \Drupal\openai_embeddings\Http\PineconeClient
   */
  protected PineconeClient $client;

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
                'id' => $item->getId() . ':' . $delta1 . ':' . $delta2,
                'values' => $value['vectors'],
                'metadata' => [
                  'content' => $value['content'],
                ],
              ];
            }
          }
        }
        else {
          $itemBase['metadata'][$field->getFieldIdentifier()] = $field->getValues();
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
    // @todo Implement search() method.
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
    // @todo Make configurable.
    return "searchapi:{$this->server->id()}:{$index->id()}";
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

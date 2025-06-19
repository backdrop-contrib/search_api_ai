<?php

namespace Drupal\search_api_ai_milvus\Plugin\search_api\backend;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Plugin\PluginFormTrait;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api_ai\Backend\SearchApiAiBackendPluginBase;
use Drupal\search_api_ai\SearchApiAiBackendInterface;
use HelgeSverre\Milvus\Milvus;

/**
 * Milvus backend for search api.
 *
 * @SearchApiBackend(
 *   id = "search_api_ai_milvus",
 *   label = @Translation("Milvus"),
 *   description = @Translation("Index items on Milvus.")
 * )
 */
class SearchApiMilvusBackend extends SearchApiAiBackendPluginBase implements PluginFormInterface, SearchApiAiBackendInterface {

  use PluginFormTrait;

  /**
   * The Milvus host.
   *
   * @var string
   *   The Milvus host.
   */
  protected string $baseHost = '';

  /**
   * The Milvus port.
   *
   * @var string
   *   The Milvus port.
   */
  protected string $port = '';

  /**
   * The Milvus API Key.
   *
   * @var string
   *   The Milvus API Key.
   */
  protected string $apiKey = '';

  /**
   * The Milvus client when created.
   *
   * @var \HelgeSverre\Milvus\Facades\Milvus
   */
  protected Milvus $client;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return parent::defaultConfiguration() + [
      'basehost' => NULL,
      'api_key' => NULL,
      'port' => '443',
      'database' => 'default',
      'collection' => '',
      'embeddings_engine' => NULL,
      'metric_type' => 'COSINE',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    // It might be a Sub form state, so we need to get the complete form state.
    if ($form_state instanceof SubformStateInterface) {
      $form_state = $form_state->getCompleteFormState();
    }

    $form['basehost'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Server'),
      '#required' => TRUE,
      '#description' => $this->t('The server to connect to. If you use Zilliz Cloud, this can be found under Public Endpoint.'),
      '#default_value' => $this->configuration['basehost'],
    ];

    $form['port'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Port'),
      '#required' => TRUE,
      '#description' => $this->t('The server port to connect to. If you use Zilliz Cloud, this is 443.'),
      '#default_value' => $this->configuration['port'],
    ];

    $form['api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('API Key'),
      '#description' => $this->t('The API key to use for authentication.'),
      '#default_value' => $this->configuration['api_key'],
    ];

    $form['database'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Database'),
      '#required' => TRUE,
      '#description' => $this->t('The database to connect to.'),
      '#default_value' => $this->configuration['database'],
      '#disabled' => $this->configuration['collection'],
    ];

    $form['collection'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Collection'),
      '#description' => $this->t('The collection to use. This will be generated if it does not exist and cannot be changed.'),
      '#default_value' => $this->configuration['collection'],
      '#required' => TRUE,
      '#pattern' => '[a-zA-Z0-9_]*',
      '#disabled' => $this->configuration['collection'],
    ];

    $form['metric_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Metric Type'),
      '#description' => $this->t('The metric type to use for the collection. This will be generated if it does not exist and cannot be changed.'),
      '#default_value' => $this->configuration['metric_type'] ?? 'COSINE',
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
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    /** @var  */
    $client = $this->getClient();
    // Validate that the connection works.
    try {
      $this->setBaseHost($form_state->getValue('basehost'));
      $this->setPort($form_state->getValue('port'));
      $this->setApiKey($form_state->getValue('api_key'));
      $client->collections()->list(
        dbName: $form_state->getValue('database'),
      );
    }
    catch (\Exception $e) {
      $form_state->setErrorByName('basehost', $this->t('Could not connect to the server. Please check the server, port and API key.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $collections = json_decode($this->getClient()->collections()->list(
      dbName: $form_state->getValue('database'),
    ), TRUE);
    // If the collection does not exist, create it.
    if (!in_array($this->configuration['collection'], $collections['data'])) {
      $this->getClient()->collections()->create(
        dbName: $form_state->getValue('database'),
        collectionName: $form_state->getValue('collection'),
        dimension: $form_state->getValue('embeddings_engine_configuration')['dimension'],
        metricType: $form_state->getValue('metric_type'),
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedFeatures() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function indexItems(IndexInterface $index, array $items) {
    $serverId = $this->server->id();
    $indexId = $index->id();
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
        $data['drupal_long_id'] = $chunkedItem['id'];
        $data['drupal_entity_id'] = $item->getId();
        $data['vector'] = $chunkedItem['values'];
        foreach ($chunkedItem['metadata'] as $key => $value) {
          $data[$key] = $value;
        }
        $this->getClient()->vector()->insert(
          collectionName: $this->configuration['collection'],
          data: $data,
        );
      }
      $successfulItemIds[] = $item->getId();
    }

    return $successfulItemIds;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteItems(IndexInterface $index, array $item_ids) {
    $milvusIds = $this->getMilvusIds($item_ids);
    $this->getClient()->vector()->delete(
      collectionName: $this->configuration['collection'],
      id: $milvusIds,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAllIndexItems(IndexInterface $index, $datasource_id = NULL) {
    $this->getClient()->collections()->drop(
      dbName: $this->configuration['database'],
      collectionName: $this->configuration['collection'],
    );
    $this->getClient()->collections()->create(
      dbName: $this->configuration['database'],
      collectionName: $this->configuration['collection'],
      dimension: $this->configuration['embeddings_engine_configuration']['dimension'],
      metricType: $this->configuration['metric_type'],
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
    // If no vector is set, we return a normal query.
    if (empty($query->getOption('query_embedding'))) {
      $response = $this->getClient()->vector()->query(
        collectionName: $this->configuration['collection'],
        limit: $query->getOption('limit', 10),
        dbName: $this->configuration['database'],
        filter: 'id not in [0]',
        outputFields: ['id', 'drupal_entity_id', 'drupal_long_id', 'content'],
      );
    }
    else {
      $response = $this->getClient()->vector()->search(
        vector: $query->getOption('query_embedding') ?? [],
        limit: $query->getOption('limit', 10),
        collectionName: $this->configuration['collection'],
        dbName: $this->configuration['database'],
        outputFields: ['id', 'drupal_entity_id', 'drupal_long_id', 'content'],
      );
    }

    $results = $query->getResults();

    $decoded_response = json_decode($response, flags: \JSON_THROW_ON_ERROR);
    foreach ($decoded_response->data as $match) {
      $item = $this->getFieldsHelper()->createItem($index, $match->drupal_long_id);
      $item->setScore($match->distance ?? 1);
      $this->extractMetadata($match, $item);
      $results->addResultItem($item);
    }
    $results->setResultCount(count($decoded_response->data));
  }

  /**
   * Extract query metadata values to a result item.
   */
  public function extractMetadata(object $result_row, ItemInterface $item): void {
    foreach ($result_row as $key => $value) {
      if ($key === 'vector' || $key === 'id' || $key === 'distance') {
        continue;
      }
      $item->setExtraData($key, $value);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function supportsDataType($type) {
    return in_array($type, [
      'embeddings',
    ]);
  }

  /**
   * Test the connection to the server.
   */
  public function testConnection(array &$form, FormStateInterface $form_state) {
    if ($form_state instanceof SubformStateInterface) {
      $form_state = $form_state->getCompleteFormState();
    }
    $state_values = $form_state->getValues();

    $basehost = $state_values['backend_config']['server']['basehost'];
    if ($basehost) {
      $this->setBaseHost($basehost);
      $this->setPort($state_values['backend_config']['server']['port']);
      $this->setApiKey($state_values['backend_config']['server']['api_key'] ?? '');
      $collections = $this->getClient()->collections()->list(
        dbName: $state_values['backend_config']['server']['database'],
      );
      $results = json_decode($collections, TRUE);
      $options[''] = $this->t('-- Select a collection --');
      foreach ($results['data'] as $collection) {
        $options[$collection] = $collection;
      }
      $options['new'] = $this->t('-- Create a new collection --');
      $form['backend_config']['collection']['#options'] = $options;
    }
    $form_state->setRebuild(TRUE);
    return $form['backend_config'];
  }

  /**
   * Get Milvus ids from drupal ids.
   *
   * @param array $drupalIds
   *   The drupal ids.
   *
   * @return array
   *   The ids.
   */
  public function getMilvusIds(array $drupalIds): array {
    $data = json_decode($this->getClient()->vector()->query(
      collectionName: $this->configuration['collection'],
      filter: "drupal_entity_id in [\"" . implode('","', $drupalIds) . "\"]",
      outputFields: ['id']
    ), TRUE);
    $ids = [];
    foreach ($data['data'] as $item) {
      $ids[] = $item['id'];
    }
    return $ids;
  }

  /**
   * Get the setup Milvus Object.
   *
   * @return \HelgeSverre\Milvus\Milvus
   *   The Milvus object.
   */
  public function getClient() {
    // Only generate once.
    if (empty($this->client)) {
      $this->client = new Milvus(
        token: $this->configuration['api_key'] ?? $this->apiKey,
        host: $this->configuration['basehost'] ?? $this->baseHost,
        port: $this->configuration['port'] ?? $this->port,
      );
    }
    return $this->client;
  }

  /**
   * Set Milvus Base Host.
   *
   * @param string $baseHost
   *   The base host.
   */
  public function setBaseHost(string $baseHost) {
    $this->baseHost = $baseHost;
  }

  /**
   * Set Milvus Port.
   *
   * @param string $port
   *   The port.
   */
  public function setPort(string $port) {
    $this->port = $port;
  }

  /**
   * Set Milvus API Key.
   *
   * @param string $apiKey
   *   The API Key.
   */
  public function setApiKey(string $apiKey) {
    $this->apiKey = $apiKey;
  }

}

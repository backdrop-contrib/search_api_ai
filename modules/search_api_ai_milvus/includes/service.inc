<?php
/**
 * @file
 * Milvus Search API backend for Backdrop CMS.
 *
 * Place this in includes/MilvusService.inc (or similar).
 * Register in your search_api_service_info() as class = 'MilvusService'.
 */

class MilvusService extends SearchApiAbstractService {
  protected $client;
  protected $options;

  /*public function __construct(SearchApiServer $server) {
    parent::__construct($server);
    $this->options = $server->options;
    // Initialize Milvus client with server options
    $client = new MilvusVectorClient($this->options);
    $this->client = $client;
  }*/

  public function __construct(SearchApiServer $server) {
    parent::__construct($server);
    // Use the server options, not the global openai_embeddings config.
    $plugin_id = $this->options['vector_client_plugin'] ?? 'milvus';
    $this->client = openai_embeddings_get_vector_client($plugin_id, $this->options);
    if (!$this->client) {
      watchdog('search_api_ai_milvus', 'Error: openai_embeddings_get_vector_client() returned NULL for plugin_id @id', ['@id' => $plugin_id], WATCHDOG_ERROR);
    }
  }

  /**
   * Returns the configuration form.
   */
  public function configurationForm($form, &$form_state) {
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
    global $search_api_ai_vectors;
    global $search_api_ai_is_indexing;
    $search_api_ai_is_indexing = TRUE;

    $serverId = $this->server->id();
    $indexId = $index->id();

    // Dynamically get vector field names for this index.
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
    $expected_dimension = search_api_ai_get_embedding_dimension();
    $collection = $this->options['collection'];
    $database = $this->options['database'];
    $successful_item_ids = [];
    $batch_vectors = [];

    $item_ids_to_index = [];
    foreach ($items as $item) {
      if (is_array($item)) $item = (object)$item;
      if (isset($item->nid)) {
        $item_ids_to_index['node'][] = is_array($item->nid) && isset($item->nid['value']) ? $item->nid['value'] : $item->nid;
      }
      elseif (isset($item->tid)) {
        $item_ids_to_index['taxonomy_term'][] = is_array($item->tid) && isset($item->tid['value']) ? $item->tid['value'] : $item->tid;
      }
      elseif (isset($item->item_id)) {
        $item_ids_to_index['paragraphs_item'][] = is_array($item->item_id) && isset($item->item_id['value']) ? $item->item_id['value'] : $item->item_id;
      }
    }

    foreach ($search_api_ai_vectors as $entry) {
      $entity_type = $entry['entity_type'];
      $entity_id   = $entry['entity_id'];

      // Only index main content chunks (the real embedding field(s)).
      if (!in_array($entry['field_name'], $vector_field_names)) continue;

      if (empty($item_ids_to_index[$entity_type]) || !in_array($entity_id, $item_ids_to_index[$entity_type])) continue;
      if (empty($entry['vector']) || count($entry['vector']) !== $expected_dimension) continue;

      $milvus_id = "entity:{$entity_type}/{$entity_id}:en:{$entry['field_name']}:{$entry['chunk_index']}";

      // Prune $entry for metadata-only fields
      $contextual_metadata = $entry;
      unset(
        $contextual_metadata['vector'],
        $contextual_metadata['content'],   // already in base metadata
        $contextual_metadata['entity_type'],
        $contextual_metadata['entity_id'],
        $contextual_metadata['field_name'],
        $contextual_metadata['chunk_index']
      );

      // Compose the metadata array
      $metadata = array_merge([
        'content'    => $entry['content'],
        'backdrop_entity_id' => "entity:{$entity_type}/{$entity_id}:en",
        'backdrop_long_id'   => $milvus_id,
        'server_id'  => $serverId,
        'index_id'   => $indexId,
        'field_name' => $entry['field_name'],
        'chunk_index'=> $entry['chunk_index'],
        'nid'        => ($entity_type == 'node')           ? $entity_id : NULL,
        'tid'        => ($entity_type == 'taxonomy_term')  ? $entity_id : NULL,
        'pid'        => ($entity_type == 'paragraphs_item')? $entity_id : NULL,
      ], $contextual_metadata);

      $batch_vectors[] = [
        'id'      => $milvus_id,
        'values'  => array_values(array_map('floatval', $entry['vector'])),
        'metadata'=> $metadata,
      ];

      $successful_item_ids[] = $entity_id;
    }

    if (!empty($batch_vectors)) {
      try {
        $this->client->upsert([
          'collection' => $collection,
          'database' => $database,
          'vectors' => $batch_vectors,
        ]);
      } catch (Exception $e) {
        watchdog('search_api_ai_milvus', '❌ Milvus indexing error: @message', [
          '@message' => $e->getMessage(),
        ], WATCHDOG_ERROR);
      }
    }
    return $successful_item_ids;
  }

  /**
   * Deletes indexed items from Milvus.
   */
  public function deleteItems($ids = 'all', SearchApiIndex $index = NULL) {
    $collection = $this->options['collection'];
    $database = $this->options['database'];
    if ($ids === 'all') {
      $this->client->delete([
        'deleteAll' => TRUE,
        'collection' => $collection,
        'database' => $database,
      ]);
    } else {
      $formatted_ids = array_map(function ($id) {
        return "entity:node/{$id}:en:*";
      }, $ids);
      $this->client->delete([
        'ids' => $formatted_ids,
        'collection' => $collection,
        'database' => $database,
      ]);
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

    // Model selection priority: index → service options → global config.
    $model =
      !empty($index_options['embedding_settings']['embeddings_engine']) ? $index_options['embedding_settings']['embeddings_engine'] :
        (!empty($this->options['embedding_model']) ? $this->options['embedding_model'] :
          config('openai_embeddings.settings')->get('model'));

    if (empty($model)) {
      watchdog('search_api_ai_milvus', 'No model configured!', [], WATCHDOG_ERROR);
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

    // Get query embedding.
    $openai_config = config('openai.settings');
    $api_key = key_get_key_value($openai_config->get('api_key'));
    $openai_api = new OpenAIApi($api_key);
    $query_embedding = $openai_api->embedding($text, $model);

    if (empty($query_embedding)) {
      throw new Exception('Failed to retrieve embedding from OpenAI.');
    }

    $top_k = $query->getOption('top_k') ?? 8;
    $outputFields = [
      'content', 'entity_id', 'entity_type', 'field_name', 'field_delta',
      'bundle', 'backdrop_entity_id', 'backdrop_long_id', 'nid'
    ];

    // Actual search
    try {
      $response = $this->client->query([
        'vector' => $query_embedding,
        'top_k' => $top_k,
        'collection' => $collection,
        'database' => $database,
        'include_metadata' => TRUE,
        'output_fields' => $outputFields,
      ]);

      $results = [];
      foreach ($response as $match) {
        $entity_type = NULL;
        $entity_id = NULL;
        if (isset($match['backdrop_entity_id']) && preg_match('/^entity:([^\/]+)\/(\d+)/', $match['backdrop_entity_id'], $m)) {
          $entity_type = $m[1];
          $entity_id = $m[2];
        } elseif (isset($match['nid'])) {
          $entity_type = 'node';
          $entity_id = $match['nid'];
        }
        if ($entity_type && $entity_id) {
          $item_id = $entity_type == $query->getIndex()->getEntityType()
            ? $entity_id : "{$entity_type}/{$entity_id}";
          $results[$item_id] = [
            'id' => $item_id,
            'score' => $match['distance'],
            'fields' => [],
            'entity' => entity_load($entity_type, $entity_id),
            'excerpt' => isset($match['content']) ? strip_tags($match['content']) : '',
          ];
        }
      }
      return [
        'result count' => count($results),
        'results' => $results,
      ];
    } catch (Exception $e) {
      watchdog('search_api_ai_milvus', '❌ Milvus query error: @message', [
        '@message' => $e->getMessage(),
      ], WATCHDOG_ERROR);
      return ['result count' => 0, 'results' => []];
    }
  }

  public function getCollection($index) {
    $collection = !empty($this->options['collection']) ? $this->options['collection'] : $index->machine_name;
    return preg_replace('/[^a-zA-Z0-9_]/', '_', $collection);
  }

  /**
   * Returns supported data types.
   */
  public function supportsDataType($type) {
    return in_array($type, ['embeddings']);
  }

}

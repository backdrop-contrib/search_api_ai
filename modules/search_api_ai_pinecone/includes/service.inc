<?php

/**
 * Search service class for Pinecone in Backdrop CMS.
 */
class PineconeService extends SearchApiAbstractService {

  protected $client;

  /**
   * PineconeService constructor.
   *
   * @param \SearchApiServer $server
   */
  public function __construct(SearchApiServer $server) {
    parent::__construct($server);
    // Use the server options, not the global openai_embeddings config.
    $plugin_id = $this->options['vector_client_plugin'] ?? 'pinecone';
    $this->client = openai_embeddings_get_vector_client($plugin_id, $this->options);
    if (!$this->client) {
      watchdog('search_api_ai_pinecone', 'Error: openai_embeddings_get_vector_client() returned NULL for plugin_id @id', ['@id' => $plugin_id], WATCHDOG_ERROR);
    }
  }

  /**
   * Returns the configuration form.
   */
  public function configurationForm($form, &$form_state) {

    $form['namespace'] = [
      '#type' => 'textfield',
      '#title' => t('Namespace'),
      '#description' => t('An optional override for the default namespace. The index ID will always be appended.'),
      '#default_value' => !empty($this->options['namespace']) ? $this->options['namespace'] : '',
      '#pattern' => '[a-zA-Z0-9_]*',
    ];

    return $form;
  }

  /**
   * Indexes items in Pinecone.
   */
  public function indexItems(SearchApiIndex $index, array $items) {
    //dpm($items, 'items');
    global $search_api_ai_vectors;
    global $search_api_ai_is_indexing;
    $search_api_ai_is_indexing = TRUE;
    //dpm($search_api_ai_vectors, 'search_api_ai_vectors');
    $expected_dimension = search_api_ai_get_embedding_dimension();
    $namespace = $this->getNamespace($index);
    $successful_item_ids = [];
    $batch_vectors = [];

    watchdog('search_api_ai_pinecone', '📌 Function called: @function with @count items (Expected Dimension: @dimension)', [
      '@function' => __METHOD__,
      '@count' => count($items),
      '@dimension' => $expected_dimension,
    ], WATCHDOG_DEBUG);

    if (empty($items)) {
      watchdog('search_api_ai_pinecone', '❌ No items to index. Exiting.', [], WATCHDOG_WARNING);
      return [];
    }

    foreach ($items as $item) {
      if (is_array($item)) {
        $item = (object) $item;
      }

      // ✅ Extract the item ID properly
      $item_id = isset($item->nid['value']) ? (int) $item->nid['value'] : (isset($item->nid) ? (int) $item->nid : NULL);
      if (empty($item_id)) {
        watchdog('search_api_ai_pinecone', '⚠️ Skipping item due to missing item_id.', [], WATCHDOG_WARNING);
        continue;
      }
      $vector_key = "node/$item_id";
      if (empty($search_api_ai_vectors[$vector_key]['vectors'])) {
        watchdog('search_api_ai_pinecone', "⚠️ No vectors found for item $vector_key.", [], WATCHDOG_WARNING);
        continue;
      }

      foreach ($search_api_ai_vectors[$vector_key]['vectors'] as $field_name => $vector_entries) {


        if (!isset($vector_entries['vectors']) || !is_array($vector_entries['vectors'])) {
          watchdog('search_api_ai_pinecone', "❌ Missing or invalid vectors for field $field_name in item $item_id.", [], WATCHDOG_ERROR);
          continue;
        }

        $vectors = $vector_entries['vectors'];

        if (count($vectors) !== $expected_dimension) {
          watchdog('search_api_ai_pinecone', "❌ Invalid vector size for field $field_name in item $item_id: Expected $expected_dimension, got " . count($vectors), [], WATCHDOG_ERROR);
          continue;
        }

        // ✅ Extract text content for metadata
        $content_text = "";
        if (isset($vector_entries['content']) && is_string($vector_entries['content'])) {
          $content_text = strip_tags($vector_entries['content']);
        }

        $pinecone_id = "entity:node/{$item_id}:en:{$field_name}:0";

        $batch_vectors[] = [
          'id' => $pinecone_id,
          'values' => array_values(array_map('floatval', $vectors)),
          'metadata' => ['content' => $content_text],
        ];
      }

      $successful_item_ids[] = $item_id;
    }

    // ✅ Send all vectors in one batch request
    if (!empty($batch_vectors)) {
      try {
        $this->client->upsert([
          'vectors' => $batch_vectors,
          'namespace' => $namespace,
        ]);

        watchdog('search_api_ai_pinecone', '🚀 Successfully indexed @count vectors.', [
          '@count' => count($batch_vectors),
        ], WATCHDOG_NOTICE);
      } catch (\Exception $e) {
        watchdog('search_api_ai_pinecone', '❌ Pinecone indexing error: @message', [
          '@message' => $e->getMessage(),
        ], WATCHDOG_ERROR);
      }
    } else {
      watchdog('search_api_ai_pinecone', '⚠️ No valid vectors to index.', [], WATCHDOG_WARNING);
    }

    return $successful_item_ids;
  }


  /**
   * Performs a search in Pinecone.
   */
  public function search(SearchApiQueryInterface $query) {
    global $search_api_ai_is_indexing;

    if (!empty($search_api_ai_is_indexing)) {
      watchdog('search_api_ai_pinecone', '🚫 search() was called during indexing. Aborting.', [], WATCHDOG_WARNING);
      return [
        'result count' => 0,
        'results' => [],
      ];
    }

    $index = $query->getIndex();
    $query_embedding = $query->getOption('query_embedding');

    if (empty($query_embedding)) {
      watchdog('search_api_ai_pinecone', '⚠️ No query embedding provided — regenerating from keywords.', [], WATCHDOG_DEBUG);

      $search_text = $query->getKeys();
      unset($search_text['#conjunction']);
      $flat_text = is_array($search_text) ? implode(' ', $search_text) : (string) $search_text;

      if (!empty($flat_text)) {
        $query_embedding = $this->openai_generate_embedding($flat_text);

        if (!empty($query_embedding) && is_array($query_embedding)) {
          watchdog('search_api_ai_pinecone', '✅ Successfully generated embedding from search text.', [], WATCHDOG_NOTICE);
        }
        else {
          watchdog('search_api_ai_pinecone', '❌ Embedding generation failed or returned invalid result.', [], WATCHDOG_ERROR);
          return [
            'result count' => 0,
            'results' => [],
          ];
        }
      }
      else {
        watchdog('search_api_ai_pinecone', '🚫 Search text is empty, cannot generate embedding.', [], WATCHDOG_ERROR);
        return [
          'result count' => 0,
          'results' => [],
        ];
      }
    }

    try {
      $response = $this->client->query([
        'vector' => $query_embedding,
        'top_k' => $query->getOption('top_k') ?? 5,
        'include_metadata' => true,
        'include_values' => true,
        'filter' => $query->getOption('filters') ?? [],
        'namespace' => $this->getNamespace($index),
      ]);

      $matches = is_array($response) && isset($response['matches']) ? $response['matches'] : [];
      //dpm($matches, 'matches');
      $results = [];

      foreach ($matches as $match) {
        if (preg_match('/^entity:([^\/]+)\/(\d+):/', $match['id'], $m)) {
          $entity_type = $m[1];
          $entity_id = $m[2];

          // ✅ Normalize item ID format
          $item_id = $index->getEntityType() === $entity_type ? $entity_id : "{$entity_type}/{$entity_id}";

          $results[$item_id] = [
            'item_id' => $item_id,
            'score' => $match['score'],
            'fields' => [],
            'extra_data' => [
              'metadata' => $match['metadata'] ?? [],
              'pinecone_id' => $match['id'],
            ],
          ];
        }
      }

      return [
        'result count' => count($results),
        'results' => $results,
      ];
    }
    catch (\Exception $e) {
      watchdog('search_api_ai_pinecone', '❌ Pinecone query error: @message', ['@message' => $e->getMessage()], WATCHDOG_ERROR);
      return [
        'result count' => 0,
        'results' => [],
      ];
    }
  }

  /**
   * Generates embeddings using OpenAI API.
   *
   * @param string $text
   *   The text to generate embeddings for.
   *
   * @return array|null
   *   Returns the embedding vector array or null if generation fails.
   */
  protected function openai_generate_embedding($text) {
    try {
      $openai_api_key_name = config_get('openai.settings', 'api_key');
      $api_key = key_get_key_value($openai_api_key_name);
      if (empty($api_key)) {
        watchdog('openai_embeddings', 'Missing OpenAI API key', [], WATCHDOG_ERROR);
        return NULL;
      }

      $model = config_get('search_api_ai.settings', 'model') ?: 'text-embedding-3-small';

      $openai = new OpenAIApi($api_key);

      // ✅ Ensure $text is a string
      $text = is_array($text) ? implode(' ', $text) : (string) $text;

      return $openai->embedding($text, $model);
    }
    catch (\Exception $e) {
      watchdog('openai_embeddings', 'Error generating embedding: @message', [
        '@message' => $e->getMessage()
      ], WATCHDOG_ERROR);
      return NULL;
    }
  }

  /**
   * Deletes indexed items from Pinecone.
   */
  public function deleteItems($ids = 'all', SearchApiIndex $index = NULL) {
    global $search_api_ai_is_indexing;

    if (!empty($search_api_ai_is_indexing)) {
      watchdog('search_api_ai_pinecone', '🚫 deleteItems() called during indexing. Aborting.', [], WATCHDOG_WARNING);
      return;
    }

    $namespace = $this->getNamespace($index);

    // ✅ Ensure the namespace exists before proceeding
    if (!$this->namespaceExists($namespace)) {
      watchdog('search_api_ai_pinecone', '⚠️ Namespace @namespace does not exist. Skipping delete request.', [
        '@namespace' => $namespace,
      ], WATCHDOG_WARNING);
      return;
    }

    $payload = [];

    if ($ids === 'all') {
      $payload = [
        'deleteAll' => true,
        'namespace' => $namespace,
      ];
    } else {
      if (empty($ids) || !is_array($ids)) {
        watchdog('search_api_ai_pinecone', '🚨 Error: No valid vector IDs provided for deletion.', [], WATCHDOG_ERROR);
        return;
      }

      $formatted_ids = array_map(function ($id) {
        return "entity:node/{$id}:en:*"; // Match Drupal format
      }, $ids);

      watchdog('search_api_ai_pinecone', '🛠️ Deleting Pinecone items: <pre>@ids</pre>', [
        '@ids' => print_r($formatted_ids, TRUE),
      ], WATCHDOG_DEBUG);

      $payload = [
        'ids' => $formatted_ids,
        'namespace' => $namespace,
      ];
    }

    try {
      $this->client->delete($payload);
      watchdog('search_api_ai_pinecone', '✅ Successfully deleted vectors.');
    } catch (\Exception $e) {
      watchdog('search_api_ai_pinecone', '❌ Pinecone deletion error: @message', [
        '@message' => $e->getMessage(),
      ], WATCHDOG_ERROR);
    }
  }

  /**
   * Checks if a namespace exists in Pinecone before attempting deletion.
   *
   * @param string $namespace
   *   The namespace to check.
   *
   * @return bool
   *   TRUE if the namespace exists, FALSE otherwise.
   */
  /**
   * Checks if a namespace exists in Pinecone by querying for any existing vector.
   *
   * @param string $namespace
   *   The namespace to check.
   *
   * @return bool
   *   TRUE if the namespace contains data, FALSE otherwise.
   */
  private function namespaceExists($namespace) {
    try {
      // Perform a dummy query in the namespace with top_k=1
      $query_parameters = [
        'vector' => array_fill(0, 1536, 0), // Dummy zero vector (1536-dim for OpenAI embeddings)
        'top_k' => 5,
        'namespace' => $namespace,
        'include_values' => false,
        'include_metadata' => false,
      ];

      $response = $this->client->query($query_parameters);

      if (!is_array($response) || empty($response['matches'])) {
        watchdog('search_api_ai_pinecone', '⚠️ Namespace @namespace does not exist (no data found).', [
          '@namespace' => $namespace,
        ], WATCHDOG_WARNING);
        return FALSE;
      }

      return TRUE;
    } catch (\Exception $e) {
      watchdog('search_api_ai_pinecone', '❌ Error checking namespace existence: @message', [
        '@message' => $e->getMessage(),
      ], WATCHDOG_ERROR);
      return FALSE;
    }
  }


  /**
   * Extracts metadata from Pinecone search results.
   */
  public function extractMetadata($result_row, $item) {
    $item->extra_data['metadata'] = $result_row['metadata'];
  }

  /**
   * Gets the namespace for Pinecone collection.
   */
  public function getNamespace(SearchApiIndex $index) {
    return ($this->options['namespace'] ?? "searchapi_{$this->server->machine_name}") . ":{$index->machine_name}";
  }

  /**
   * Determines if the service supports a given data type.
   */
  public function supportsDataType($type) {
    return in_array($type, ['embeddings']);
  }
}

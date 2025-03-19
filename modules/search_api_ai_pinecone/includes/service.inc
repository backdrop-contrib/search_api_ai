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

    // Load the Pinecone vector client.
    $plugin_id = config_get('openai_embeddings.settings', 'vector_client_plugin');
    $this->client = openai_embeddings_get_vector_client($plugin_id);

    if (!$this->client) {
      watchdog('search_api_ai_pinecone', 'Error: openai_embeddings_get_vector_client() returned NULL.', WATCHDOG_ERROR);
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
    global $search_api_ai_vectors;
    global $search_api_ai_is_indexing;
    $search_api_ai_is_indexing = TRUE;

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

      // ✅ Iterate over all fields in `vectors` (title, body, etc.)
      foreach ($search_api_ai_vectors[$item_id]['vectors'] as $field_name => $vector_entries) {

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
      return [];
    }

    watchdog('search_api_ai_pinecone', '🔍 search() method called.');

    $index = $query->getIndex();

    $query_embedding = $query->getOption('query_embedding');

    if (empty($query_embedding)) {
      watchdog('search_api_ai_pinecone', '⚠️ Query embedding missing! Attempting to regenerate.', [], WATCHDOG_WARNING);

      // Extract search text (keywords) from the query
      $search_text = $query->getKeys();

      if (!empty($search_text)) {
        // Generate embedding using OpenAI or another embedding function
        $query_embedding = openai_generate_embedding($search_text);  // You need to implement this function

        watchdog('search_api_ai_pinecone', '🛠️ Generated embedding: <pre>@embedding</pre>', [
          '@embedding' => json_encode(array_slice($query_embedding, 0, 5)) . '...',
        ], WATCHDOG_NOTICE);
      } else {
        watchdog('search_api_ai_pinecone', '🚨 Cannot generate embedding: No search text found.', [], WATCHDOG_ERROR);
        return [];
      }
    }

    // Log the query embedding
    watchdog('search_api_ai_pinecone', '🔍 Query Embedding: @embedding', [
      '@embedding' => json_encode(array_slice($query_embedding ?? [], 0, 5)) . '...',
    ], WATCHDOG_NOTICE);

    if (empty($query_embedding) || !is_array($query_embedding)) {
      watchdog('search_api_ai_pinecone', '⚠️ No valid query_embedding found! Aborting search.', [], WATCHDOG_WARNING);
      return [];
    }

    // Construct Pinecone query parameters
    $query_parameters = [
      'vector' => $query_embedding,
      'top_k' => $query->getOption('top_k') ?? 10,
      'include_metadata' => $query->getOption('include_metadata') ?? true,
      'include_values' => true,
      'filter' => $query->getOption('filters') ?? [],
      'namespace' => $this->getNamespace($index),
    ];
    //dpm($query_parameters);

    watchdog('search_api_ai_pinecone', '🔍 Pinecone Query Parameters: <pre>@query</pre>', [
      '@query' => print_r($query_parameters, TRUE),
    ], WATCHDOG_NOTICE);

    try {
      $response = $this->client->query($query_parameters);

      if (!is_object($response) || !method_exists($response, 'getBody')) {
        watchdog('search_api_ai_pinecone', '❌ Invalid response from Pinecone', [], WATCHDOG_ERROR);
        return [];
      }

      $decoded_response = json_decode($response->getBody()->getContents(), true);
      if (empty($decoded_response) || !isset($decoded_response['matches'])) {
        watchdog('search_api_ai_pinecone', '❌ Pinecone returned an empty or invalid response.', [], WATCHDOG_ERROR);
        return [];
      }

      return ['result count' => count($decoded_response['matches']), 'matches' => $decoded_response['matches']];
    } catch (\Exception $e) {
      watchdog('search_api_ai_pinecone', '❌ Pinecone query error: @message', [
        '@message' => $e->getMessage(),
      ], WATCHDOG_ERROR);
      return [];
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
        'top_k' => 1,
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

<?php

/**
 * Search service class for Pinecone in Backdrop CMS.
 */

require_once backdrop_get_path('module', 'search_api_ai') . '/includes/SearchApiAiBackendPluginBase.inc';
require_once backdrop_get_path('module', 'search_api_ai') . '/includes/SearchApiBackendInterface.inc';
require_once backdrop_get_path('module', 'search_api_ai') . '/includes/SearchApiAiBackend.inc';

class PineconeService extends SearchApiAbstractService {

  /**
   * @var SearchApiAiPineconeVectorClient
   */
  protected $client;

  /**
   * {@inheritdoc}
   */
  public function __construct(SearchApiServer $server) {
    parent::__construct($server);
    require_once __DIR__ . '/SearchApiAiPineconeVectorClient.php';
    // Client reads $this->options (server settings) incl. key names.
    $this->client = new SearchApiAiPineconeVectorClient($this->options);
  }

  /**
   * Server configuration form (saved by the Search API backend layer).
   */
  public function configurationForm($form, &$form_state) {
    // Add embedding engine fields from search_api_ai.
    $form = search_api_ai_update_embedding_configuration_form($form, $form_state);

    // Optional namespace prefix (index ID is always appended).
    $form['namespace'] = [
      '#type' => 'textfield',
      '#title' => t('Namespace'),
      '#description' => t('Optional prefix. The index ID will always be appended.'),
      '#default_value' => !empty($this->options['namespace']) ? $this->options['namespace'] : '',
      '#pattern' => '[a-zA-Z0-9_\-:]*',
    ];

    // Pinecone API Key — stored via Key module.
    $form['pinecone_api_key'] = [
      '#type' => 'key_select',
      '#title' => t('Pinecone API Key'),
      '#default_value' => !empty($this->options['pinecone_api_key']) ? $this->options['pinecone_api_key'] : '',
      '#options' => function_exists('key_get_key_names_as_options') ? key_get_key_names_as_options() : [],
      '#key_filters' => ['type' => 'authentication'],
      '#required' => TRUE,
      '#description' => t('Select the Key that stores your Pinecone API key.'),
    ];

    // Pinecone Hostname/Base URL — stored via Key module (e.g. https://...pinecone.io).
    $form['pinecone_hostname'] = [
      '#type' => 'key_select',
      '#title' => t('Pinecone Hostname / Base URL'),
      '#default_value' => !empty($this->options['pinecone_hostname']) ? $this->options['pinecone_hostname'] : '',
      '#options' => function_exists('key_get_key_names_as_options') ? key_get_key_names_as_options() : [],
      '#key_filters' => ['type' => 'authentication'],
      '#required' => TRUE,
      '#description' => t('Select the Key that stores your Pinecone base URL (e.g., <code>https://...pinecone.io</code>).'),
    ];

    // Optional: default Top-K (nice to have; can be omitted).
    $form['top_k'] = [
      '#type' => 'number',
      '#title' => t('Default Top-K'),
      '#default_value' => isset($this->options['top_k']) ? (int) $this->options['top_k'] : 5,
      '#min' => 1,
      '#step' => 1,
      '#description' => t('Default number of results; can be overridden per query.'),
    ];

    return $form;
  }

  /**
   * Index items to Pinecone (batch upsert).
   */
  public function indexItems(SearchApiIndex $index, array $items) {
    global $search_api_ai_is_indexing;
    $search_api_ai_is_indexing = TRUE;

    // Dimension from index or global fallback.
    $expected_dimension = isset($index->options['embedding_settings']['embeddings_engine_configuration']['dimension'])
      ? (int) $index->options['embedding_settings']['embeddings_engine_configuration']['dimension']
      : search_api_ai_get_embedding_dimension();

    $namespace = $this->getNamespace($index);
    $successful_item_ids = [];
    $batch_vectors = [];

    if (empty($items)) {
      watchdog('search_api_ai_pinecone', 'No items to index.', [], WATCHDOG_WARNING);
      return [];
    }

    foreach ($items as $item) {
      if (is_array($item)) {
        $item = (object) $item;
      }

      $item_id = isset($item->nid['value'])
        ? (int) $item->nid['value']
        : (isset($item->nid) ? (int) $item->nid : NULL);

      if (empty($item_id)) {
        watchdog('search_api_ai_pinecone', 'Skipping: missing item_id.', [], WATCHDOG_WARNING);
        continue;
      }

      // ✅ Use the normalizer (supports flat + legacy).
      $rows = search_api_ai_normalize_vectors('node', $item_id, $expected_dimension);

      if (empty($rows)) {
        watchdog('search_api_ai_pinecone', "No vectors for node/$item_id.", [], WATCHDOG_WARNING);
        continue;
      }

      foreach ($rows as $r) {
        // Build a unique Pinecone ID per chunk.
        $pinecone_id = "entity:node/{$item_id}:en:{$r['field_name']}:{$r['chunk_index']}";

        $batch_vectors[] = [
          'id'       => $pinecone_id,
          'values'   => $r['values'],
          'metadata' => $r['metadata'],
          // keep this if your client filters on it
          'llm_vector_embedding' => $r['llm_vector_embedding'],
        ];
      }

      $successful_item_ids[] = $item_id;
    }

    if (!empty($batch_vectors)) {
      try {
        $this->client->upsert([
          'vectors'   => $batch_vectors,
          'namespace' => $namespace,
        ]);
        watchdog('search_api_ai_pinecone', 'Indexed @count vectors.', ['@count' => count($batch_vectors)], WATCHDOG_NOTICE);
      }
      catch (\Exception $e) {
        watchdog('search_api_ai_pinecone', 'Upsert error: @m', ['@m' => $e->getMessage()], WATCHDOG_ERROR);
      }
    }

    return $successful_item_ids;
  }

  /**
   * Vector search in Pinecone.
   * Safeguard: Prevent searches during indexing. Logs a warning if attempted.
   * Indexing is NOT interrupted if a search is attempted; only the search is blocked.
   */
  public function search(SearchApiQueryInterface $query) {
    global $search_api_ai_is_indexing;
    if (!empty($search_api_ai_is_indexing)) {
      // Log a warning if a search is attempted during indexing.
      // Indexing continues uninterrupted; only the search is blocked.
      watchdog('search_api_ai_pinecone', 'Search attempted during indexing. Aborted.', [], WATCHDOG_WARNING);
      return ['result count' => 0, 'results' => []];
    }

    $index = $query->getIndex();
    $index_options = isset($index->options) ? $index->options : [];

    // Model selection priority: index → service options.
    $model = !empty($index_options['embedding_settings']['embeddings_engine'])
      ? $index_options['embedding_settings']['embeddings_engine']
      : (!empty($this->options['embeddings_engine']) ? $this->options['embeddings_engine'] : NULL);

    if (empty($model)) {
      watchdog(
        'search_api_ai_pinecone',
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
      watchdog('search_api_ai_pinecone', 'Search embedding: model=@model, stripped=@stripped, text_len=@len, api_class=@class', [
        '@model' => $model,
        '@stripped' => $stripped_model,
        '@len' => strlen($text),
        '@class' => get_class($api),
      ], WATCHDOG_DEBUG);
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

    // Get expected dimension from index embedding settings, fallback to global.
    $expected_dimension = isset($index->options['embedding_settings']['embeddings_engine_configuration']['dimension'])
      ? (int) $index->options['embedding_settings']['embeddings_engine_configuration']['dimension']
      : search_api_ai_get_embedding_dimension();

    if (empty($query_embedding) || !is_array($query_embedding) || count($query_embedding) !== $expected_dimension) {
      watchdog('search_api_ai_pinecone', 'Query embedding dimension mismatch or empty.', [], WATCHDOG_ERROR);
      return ['result count' => 0, 'results' => []];
    }

    try {
      $response = $this->client->query([
        'vector' => $query_embedding,
        'top_k' => $query->getOption('top_k') ?? ($this->options['top_k'] ?? 5),
        'include_metadata' => TRUE,
        'namespace' => $this->getNamespace($index),
      ]);

      $matches = is_array($response) && isset($response['matches']) ? $response['matches'] : [];
      $results = [];

      foreach ($matches as $match) {
        if (preg_match('/^entity:([^\/]+)\/(\d+):/', $match['id'], $m)) {
          $entity_type = $m[1];
          $entity_id = $m[2];

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

      return ['result count' => count($results), 'results' => $results];
    }
    catch (\Exception $e) {
      watchdog('search_api_ai_pinecone', 'Query error: @m', ['@m' => $e->getMessage()], WATCHDOG_ERROR);
      return ['result count' => 0, 'results' => []];
    }
  }

  /**
   * Delete vectors.
   */
  public function deleteItems($ids = 'all', SearchApiIndex $index = NULL) {
    global $search_api_ai_is_indexing;
    if (!empty($search_api_ai_is_indexing)) {
      watchdog('search_api_ai_pinecone', 'deleteItems() during indexing; abort.', [], WATCHDOG_WARNING);
      return;
    }

    $namespace = $this->getNamespace($index);
    $payload = ['namespace' => $namespace];

    if ($ids === 'all') {
      $payload['deleteAll'] = TRUE;
    } else {
      if (empty($ids) || !is_array($ids)) {
        watchdog('search_api_ai_pinecone', 'No valid IDs for deletion.', [], WATCHDOG_ERROR);
        return;
      }
      $payload['ids'] = array_map(function ($id) {
        return "entity:node/{$id}:en:*";
      }, $ids);
    }

    try {
      $this->client->delete($payload);
      watchdog('search_api_ai_pinecone', 'Delete sent for @ns.', ['@ns' => $namespace], WATCHDOG_NOTICE);
    } catch (\Exception $e) {
      watchdog('search_api_ai_pinecone', 'Delete error: @m', ['@m' => $e->getMessage()], WATCHDOG_ERROR);
    }
  }

  /**
   * Extract metadata from a result row.
   */
  public function extractMetadata($result_row, $item) {
    $item->extra_data['metadata'] = $result_row['metadata'];
  }

  public function getNamespace(SearchApiIndex $index) {
    $ns = isset($this->options['namespace']) ? trim((string) $this->options['namespace']) : '';
    if ($ns === '') {
      $ns = $index->machine_name; // e.g. "pinecone_search"
    }
    // Pinecone-friendly chars only
    return preg_replace('/[^A-Za-z0-9_\-]/', '_', $ns);
  }

  /**
   * Supported data types.
   */
  public function supportsDataType($type) {
    return in_array($type, ['embeddings']);
  }
}

<?php

require_once backdrop_get_path('module', 'search_api_ai') . '/includes/SearchApiAiBackendPluginBase.inc';;
require_once 'SearchApiAiMilvusV2.php';
use GuzzleHttp\Client as GuzzleClient;

/**
 * Milvus vector client for OpenAI Embeddings (Backdrop CMS style).
 */
class SearchApiAiMilvusVectorClient extends SearchApiAiVectorClientBase {

  /**
   * @var SearchApiAiMilvusV2
   */
  protected $milvus;

  /**
   * @var array
   */
  protected $options = [];

  /**
   * Constructor.
   */
  public function __construct(array $options = []) {
    parent::__construct();
    $this->options = $options;
    $client = $this->getHttpClient([
      'timeout' => 30,
      'http_errors' => false,
    ]);
    $this->milvus = new SearchApiAiMilvusV2($client);

    // Support both openai_embeddings and search_api_ai settings formats
    $server = $options['server'] ?? $options['milvus_server'] ?? $options['basehost'] ?? '';
    $port = $options['port'] ?? $options['milvus_port'] ?? '';
    $api_key = $options['api_key'] ?? key_get_key_value($options['milvus_token'] ?? '') ?? '';

    // Ensure server URL is in the correct format for Zilliz detection
    if (!empty($server)) {
      // If the URL doesn't include the protocol and it's a Zilliz cloud URL, add https://
      if (!preg_match('~^https?://~i', $server) &&
        preg_match('~(zillizcloud\.com|cloud\.zilliz\.com)~', $server)) {
        $server = 'https://' . $server;
      }
    }

    $this->milvus->setBaseUrl($server);
    $this->milvus->setPort($port);

    if (!empty($api_key)) {
      $this->milvus->setApiKey($api_key);
    }
  }

  /**
   * Insert or update vectors.
   * Required by VectorClientBase.
   */
  public function upsert(array $parameters) {
    return $this->upsertInternal($parameters);
  }

  /**
   * Insert or update vectors (internal logic).
   */
  public function upsertInternal(array $item) {
    $collection = $item['collection'];
    $database = isset($item['database']) ? $item['database'] : 'default';

    foreach ($item['vectors'] as $orig_vector) {
      $vector = $orig_vector;

      // Remove 'id' field if present (unless using manual PKs and autoID is off)
      if (isset($vector['id'])) {
        unset($vector['id']);
      }

      // Milvus expects 'vector', not 'values'
      if (isset($vector['values'])) {
        $vector['vector'] = $vector['values'];
        unset($vector['values']);
      }

      // Flatten 'metadata' to top-level fields (optional, but handy for filtering)
      if (isset($vector['metadata']) && is_array($vector['metadata'])) {
        foreach ($vector['metadata'] as $k => $v) {
          $vector[$k] = $v;
        }
        unset($vector['metadata']);
      }

      $result = $this->milvus->insertIntoCollection($collection, $vector, $database);

      // If insert fails due to missing collection, try to create collection and retry ONCE.
      if (isset($result['code']) && $result['code'] == 100) {
        $dimension = isset($vector['vector']) ? count($vector['vector']) : 1536; // fallback
        $metricType = strtoupper($this->settings['metric_type'] ?? 'COSINE');

        // Ensure dimension is an integer
        $dimension = (int)$dimension;

        $create = $this->milvus->createCollection($collection, $database, $dimension, $metricType);

        // Log collection creation errors
        if (empty($create) || (isset($create['code']) && $create['code'] !== 0 && $create['code'] !== 200)) {
          watchdog('openai_embeddings', 'Failed to create Milvus collection @collection: @error', [
            '@collection' => $collection,
            '@error' => print_r($create, TRUE)
          ], WATCHDOG_ERROR);
          continue; // Don’t retry insert if creation failed
        } else {
          watchdog('openai_embeddings', 'Milvus collection @collection created. Retrying insert.', [
            '@collection' => $collection
          ], WATCHDOG_NOTICE);
        }

        // Try insert again
        $result = $this->milvus->insertIntoCollection($collection, $vector, $database);
      }

      // Log any final errors
      if (empty($result) || (isset($result['code']) && $result['code'] !== 0 && $result['code'] !== 200)) {
        watchdog('openai_embeddings', 'Milvus insert failed: @result', ['@result' => print_r($result, 1)], WATCHDOG_ERROR);
      }
    }
  }

  /**
   * Stats (placeholder).
   */
  public function stats() {
    return [];
  }

  /**
   * Search for vectors.
   */
  public function search($collection, $vector, $top_k = 10, $outputFields = ['content'], $database = 'default', $filter = '') {
    $result = $this->milvus->search(
      $collection,
      $vector,
      $outputFields,
      $filter,
      $top_k * 2, // Request more results to account for duplicates
      0,
      $database
    );
    if (!isset($result['data']) || !is_array($result['data'])) {
      return [];
    }

    // Deduplicate based on entity_id + field_name combination
    $deduplicated = [];
    $seen = [];
    foreach ($result['data'] as $item) {
      $key = NULL;

      if (isset($item['entity_id'], $item['entity_type'])) {
        $key = $item['entity_type'] . ':' . $item['entity_id'];
      }
      elseif (isset($item['backdrop_entity_id'])) {
        $key = $item['backdrop_entity_id'];
      }
      elseif (isset($item['nid'])) {
        $key = 'node:' . $item['nid'];
      }
      elseif (preg_match('/^entity:([^\/]+)\/(\d+)/', $item['backdrop_long_id'] ?? '', $matches)) {
        $key = $matches[1] . ':' . $matches[2];
      }

      if (!$key) {
        continue;
      }

      if (isset($item['field_name'])) {
        $key .= ':' . $item['field_name'];
      }

      if (!isset($seen[$key])) {
        $seen[$key] = TRUE;
        $deduplicated[] = $item;
        if (count($deduplicated) >= $top_k) {
          break;
        }
      }
    }
    return $deduplicated;
  }

  /**
   * Query for metadata (or vector-less fetch).
   */
  public function query(array $parameters) {
    if (!empty($parameters['vector'])) {
      $collection   = $parameters['collection'] ?? '';
      $vector       = $parameters['vector'];
      $top_k        = $parameters['top_k'] ?? 10;
      $outputFields = $parameters['output_fields'] ?? ['content'];
      $database     = $parameters['database'] ?? 'default';
      $filter       = $parameters['filter'] ?? '';
      return $this->search($collection, $vector, $top_k, $outputFields, $database, $filter);
    }
    else {
      $collection   = $parameters['collection'] ?? '';
      $outputFields = $parameters['output_fields'] ?? ['id', 'content'];
      $limit        = $parameters['limit'] ?? 10;
      $database     = $parameters['database'] ?? 'default';
      $filter       = $parameters['filter'] ?? 'id not in [0]';
      return $this->queryMetadata($collection, $outputFields, $limit, $database, $filter);
    }
  }

  /**
   * Delete: supports ids, filter, or deleteAll (drop + recreate).
   */
  // SearchApiAiMilvusVectorClient.php
  public function delete(array $parameters) {
    $collection = $parameters['collection'] ?? '';
    $database   = $parameters['database']   ?? 'default';
    $ids        = $parameters['ids']        ?? [];
    $filter     = $parameters['filter']     ?? null;   // string expr
    $deleteAll  = !empty($parameters['deleteAll']);

    if (!$collection) {
      throw new \InvalidArgumentException('delete requires "collection".');
    }

    // 1) Explicit primary-key IDs
    if (!empty($ids)) {
      return $this->milvus->deleteFromCollection($collection, $ids, $database);
    }

    // 2) Delete EVERYTHING but keep the collection (fast for re-index)
    if ($deleteAll) {
      // Most gateways accept 'true'; fallback to 'id >= 0' if needed.
      $res = $this->milvus->deleteByExpr($collection, 'true', $database);
      if (!is_array($res) || (isset($res['code']) && $res['code'] !== 0 && $res['code'] !== 200)) {
        $res = $this->milvus->deleteByExpr($collection, 'id >= 0', $database);
      }
      return $res;
    }

    // 3) Delete by caller-provided filter expression
    if (is_string($filter) && trim($filter) !== '') {
      return $this->milvus->deleteByExpr($collection, $filter, $database);
    }

    throw new \InvalidArgumentException('delete requires non-empty "ids", or "filter", or "deleteAll".');
  }

  /**
   * Helper to fetch PK ids for a filter expression.
   */
  private function collectIdsByFilter(string $collection, string $expr, string $database, int $limit = 50000): array {
    $res = $this->milvus->query($collection, ['id'], $expr, $limit, 0, $database);

    // Handle both array and object styles.
    $rows = [];
    if (is_array($res)) {
      $rows = $res['data'] ?? [];
    } elseif (is_object($res)) {
      $rows = isset($res->data) ? (array) $res->data : [];
    }

    $ids = [];
    foreach ($rows as $row) {
      if (is_array($row)) {
        if (isset($row['id'])) { $ids[] = (int)$row['id']; continue; }
        if (isset($row['pk'])) { $ids[] = (int)$row['pk']; continue; }
      } elseif (is_object($row)) {
        if (isset($row->id))   { $ids[] = (int)$row->id;  continue; }
        if (isset($row->pk))   { $ids[] = (int)$row->pk;  continue; }
      }
    }
    return array_values(array_unique($ids));
  }

  /**
   * Metadata-only query helper (kept for completeness).
   */
  public function queryMetadata($collection, $outputFields = ['id', 'content'], $limit = 10, $database = 'default', $filter = 'id not in [0]') {
    $result = $this->milvus->query(
      $collection,
      $outputFields,
      $filter,
      $limit,
      0,
      $database
    );
    if (is_array($result)) {
      return $result['data'] ?? [];
    }
    if (is_object($result)) {
      return isset($result->data) ? (array) $result->data : [];
    }
    return [];
  }

  /**
   * List collections.
   */
  public function listCollections($database = 'default') {
    return $this->milvus->listCollections($database);
  }

  /**
   * Create collection (if needed).
   */
  public function createCollection($collection, $dimension, $metricType = 'COSINE', $database = 'default') {
    $dimension = (int)$dimension;
    try {
      $result = $this->milvus->createCollection($collection, $database, $dimension, strtoupper($metricType));
      if (isset($result['code']) && $result['code'] != 0 && $result['code'] != 200) {
        watchdog('openai_embeddings', 'Failed to create Milvus collection @collection: @error', [
          '@collection' => $collection,
          '@error' => print_r($result, TRUE)
        ], WATCHDOG_ERROR);
      }
      return $result;
    } catch (Exception $e) {
      watchdog('openai_embeddings', 'Exception creating Milvus collection @collection: @message', [
        '@collection' => $collection,
        '@message' => $e->getMessage()
      ], WATCHDOG_ERROR);
      return ['code' => 1100, 'message' => $e->getMessage()];
    }
  }

  /**
   * Describe a collection.
   */
  public function describeCollection($database, $collection) {
    return $this->milvus->describeCollection($database, $collection);
  }
}


<?php

require_once backdrop_get_path('module', 'search_api_ai') . '/includes/SearchApiAiBackendPluginBase.inc';

use GuzzleHttp\Client as GuzzleClient;
use Psr\Http\Message\ResponseInterface;

/**
 * Pinecone vector client class.
 */
class SearchApiAiPineconeVectorClient extends SearchApiAiVectorClientBase {

  const DEFAULT_TOP_K = 5;

  /**
   * @var array
   */
  protected $options = [];

  /**
   * @param array $options
   *   Options from the Search API server ($this->options).
   */
  public function __construct(array $options = []) {
    $this->options = $options;
  }

  /**
   * Resolve an option from $this->options (not global config).
   *
   * @param string $key
   * @param string $description
   * @param bool $resolve_key_name_with_key_module
   *
   * @return string
   * @throws \Exception
   */
  protected function resolveOption(string $key, string $description, bool $resolve_key_name_with_key_module = FALSE): string {
    $value = $this->options[$key] ?? NULL;

    // If it's a Key name, resolve to secret value.
    if ($resolve_key_name_with_key_module && $value) {
      $resolved = key_get_key_value($value);
      if (!is_string($resolved) || $resolved === '') {
        watchdog('search_api_ai_pinecone', "Invalid $description (empty resolved value) for option @key.", ['@key' => $key], WATCHDOG_ERROR);
        throw new \Exception("Invalid or missing $description.");
      }
      $value = $resolved;
    }

    if (!is_string($value) || trim($value) === '') {
      watchdog('search_api_ai_pinecone', "Missing $description for option @key.", ['@key' => $key], WATCHDOG_ERROR);
      throw new \Exception("Invalid or missing $description.");
    }

    return trim($value);
  }

  /**
   * Build a Pinecone HTTP client.
   *
   * @return \GuzzleHttp\Client
   * @throws \Exception
   */
  protected function getPineconeClient(): GuzzleClient {
    // Resolve secrets (Key module).
    $api_key = $this->resolveOption('pinecone_api_key', 'Pinecone API key', TRUE);
    $host    = $this->resolveOption('pinecone_hostname', 'Pinecone hostname/base URI', TRUE);

    // Normalize/validate base URI.
    $host = rtrim($host, '/');
    if (strpos($host, 'http://') !== 0 && strpos($host, 'https://') !== 0) {
      // Prefer TLS.
      $host = 'https://' . $host;
    }

    // Very loose URL validation.
    if (!filter_var($host, FILTER_VALIDATE_URL)) {
      throw new \Exception("Invalid Pinecone base URI: $host");
    }

    // NOTE: Pinecone expects header "Api-Key" or "Authorization: Bearer" depending on endpoint.
    // The vector service (index host) accepts "Api-Key".
    $headers = [
      'Api-Key'      => $api_key,
      'Content-Type' => 'application/json',
      'Accept'       => 'application/json',
    ];

    $client = $this->getHttpClient([
      'base_uri' => $host,
      'headers'  => $headers,
      // Optional: tweak timeouts if you like
      //'timeout'  => 20,
    ]);

    if (!$client instanceof GuzzleClient) {
      throw new \Exception('Invalid HTTP client returned.');
    }

    return $client;
  }

  /**
   * Helper: safe Top-K.
   */
  protected function resolveTopK($params): int {
    $from_server = isset($this->options['top_k']) ? (int) $this->options['top_k'] : self::DEFAULT_TOP_K;
    $from_call   = isset($params['top_k']) ? (int) $params['top_k'] : NULL;
    $k = $from_call ?: $from_server;
    return max(1, $k);
  }

  /**
   * Log payload to watchdog in DEBUG.
   */
  protected function logPayload(string $action, array $payload): void {
    watchdog('search_api_ai_pinecone', "Pinecone $action payload: @payload", [
      '@payload' => json_encode($payload, JSON_PRETTY_PRINT),
    ], WATCHDOG_DEBUG);
  }

  /**
   * Log response to watchdog in DEBUG.
   */
  protected function logResponse(string $action, $response): void {
    watchdog('search_api_ai_pinecone', "Pinecone $action response: @response", [
      '@response' => is_string($response) ? $response : print_r($response, TRUE),
    ], WATCHDOG_DEBUG);
  }

  /**
   * Error handler.
   */
  protected function handleError(string $action, \Exception $e): void {
    watchdog('search_api_ai_pinecone', "Error during Pinecone $action: @message", [
      '@message' => $e->getMessage(),
    ], WATCHDOG_ERROR);
    throw $e;
  }

  /**
   * Query vectors.
   *
   * Expected $parameters:
   *  - vector (array<float>) REQUIRED
   *  - top_k (int) OPTIONAL
   *  - namespace (string) OPTIONAL
   *  - include_metadata (bool) OPTIONAL (defaults true)
   *  - expected_dimension (int) OPTIONAL
   */
  public function query(array $parameters) {
    $client = $this->getPineconeClient();
    $expected_dimension = isset($parameters['expected_dimension']) ? (int)$parameters['expected_dimension'] : 1536;
    $vector = $parameters['vector'];
    if (!is_array($vector) || count($vector) !== $expected_dimension) {
      watchdog('search_api_ai_pinecone', 'Query vector dimension mismatch: expected @exp, got @got', [
        '@exp' => $expected_dimension,
        '@got' => is_array($vector) ? count($vector) : 'N/A',
      ], WATCHDOG_ERROR);
      throw new \Exception('Query vector dimension mismatch.');
    }
    $payload = [
      'vector'          => $vector,
      'topK'            => $this->resolveTopK($parameters),
      'includeMetadata' => array_key_exists('include_metadata', $parameters) ? (bool) $parameters['include_metadata'] : TRUE,
    ];
    if (!empty($parameters['namespace'])) {
      $payload['namespace'] = $parameters['namespace'];
    }
    $this->logPayload('query', $payload);
    try {
      $response = $client->post('/query', ['json' => $payload]);
      $data = json_decode($response->getBody()->getContents(), TRUE);
      $this->logResponse('query', $data);
      return $data ?: [];
    } catch (\Exception $e) {
      $this->handleError('query', $e);
    }
  }

  /**
   * Upsert vectors.
   *
   * Expected $parameters:
   *  - vectors (array) REQUIRED
   *  - namespace (string) OPTIONAL
   *  - expected_dimension (int) OPTIONAL
   */
  public function upsert(array $parameters): ?ResponseInterface {
    if (empty($parameters['vectors'])) {
      throw new \Exception('Vectors are required for Pinecone upsert.');
    }
    $expected_dimension = isset($parameters['expected_dimension']) ? (int)$parameters['expected_dimension'] : 1536;
    $filtered_vectors = [];
    foreach ($parameters['vectors'] as $vector) {
      // Only upsert vectors for fields marked as llm_vector_embedding if present.
      if (isset($vector['llm_vector_embedding']) && !$vector['llm_vector_embedding']) {
        continue;
      }
      if (is_array($vector['values']) && count($vector['values']) === $expected_dimension) {
        $filtered_vectors[] = $vector;
      } else {
        watchdog('search_api_ai_pinecone', 'Upsert vector dimension mismatch: expected @exp, got @got', [
          '@exp' => $expected_dimension,
          '@got' => is_array($vector['values']) ? count($vector['values']) : 'N/A',
        ], WATCHDOG_WARNING);
      }
    }
    if (empty($filtered_vectors)) {
      throw new \Exception('No valid vectors to upsert after dimension validation.');
    }
    $payload = [
      'vectors' => $filtered_vectors,
    ];
    if (!empty($parameters['namespace'])) {
      $payload['namespace'] = $parameters['namespace'];
    }
    $this->logPayload('upsert', $payload);
    try {
      $client = $this->getPineconeClient();
      $response = $client->post('/vectors/upsert', ['json' => $payload]);
      $data = json_decode($response->getBody()->getContents(), TRUE);
      $this->logResponse('upsert', $data);

      return $response;
    } catch (\Exception $e) {
      $this->handleError('upsert', $e);
      return NULL;
    }
  }

  /**
   * Describe index stats (namespaces, counts).
   *
   * @return array of rows for display
   */
  public function stats(): array {
    try {
      $client = $this->getPineconeClient();
      $response = $client->post('/describe_index_stats');
      $stats = json_decode($response->getBody()->getContents(), TRUE);
      $this->logResponse('stats', $stats);

      $rows = [];
      if (!empty($stats['namespaces']) && is_array($stats['namespaces'])) {
        foreach ($stats['namespaces'] as $namespace => $info) {
          $rows[] = [
            'Namespace'    => $namespace !== '' ? $namespace : t('No namespace'),
            'Vector Count' => $info['vectorCount'] ?? 0,
          ];
        }
      }
      return $rows;
    } catch (\Exception $e) {
      $this->handleError('stats', $e);
      return [];
    }
  }

  /**
   * Delete vectors by IDs or filter.
   *
   * Expected $parameters:
   *  - ids (array<string>) OPTIONAL
   *  - filter (array) OPTIONAL
   *  - namespace (string) OPTIONAL
   */
  public function delete(array $parameters): ?ResponseInterface {
    if (empty($parameters['ids']) && empty($parameters['filter']) && empty($parameters['deleteAll'])) {
      throw new \Exception('Provide "ids", "filter", or "deleteAll" for Pinecone delete().');
    }

    $payload = [];

    if (!empty($parameters['ids'])) {
      $payload['ids'] = array_values($parameters['ids']);
    }
    if (!empty($parameters['filter'])) {
      $payload['filter'] = $parameters['filter'];
    }
    if (!empty($parameters['namespace'])) {
      $payload['namespace'] = $parameters['namespace'];
    }
    if (!empty($parameters['deleteAll'])) {
      $payload['deleteAll'] = TRUE;
    }

    $this->logPayload('delete', $payload);

    try {
      $client = $this->getPineconeClient();
      $response = $client->post('/vectors/delete', ['json' => $payload]);

      // Return the PSR-7 response object.
      return $response instanceof ResponseInterface ? $response : NULL;
    } catch (\Exception $e) {
      $this->handleError('delete', $e);
      return NULL;
    }
  }

  /**
   * Delete ALL vectors in a namespace.
   *
   * Expected $parameters:
   *  - namespace (string) REQUIRED
   */
  public function deleteAll(array $parameters): void {
    if (empty($parameters['namespace'])) {
      throw new \Exception('A "namespace" is required for deleteAll().');
    }

    $payload = [
      'deleteAll' => TRUE,
      'namespace' => $parameters['namespace'],
    ];

    $this->logPayload('deleteAll', $payload);

    try {
      $client = $this->getPineconeClient();
      $client->post('/vectors/delete', ['json' => $payload]);
    } catch (\Exception $e) {
      $this->handleError('deleteAll', $e);
    }
  }
}

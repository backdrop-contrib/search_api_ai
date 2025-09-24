<?php

use GuzzleHttp\Client as GuzzleClient;

/**
 * Base class for vector clients in Backdrop CMS.
 */
abstract class SearchApiAiVectorClientBase {

  /**
   * Constructor to load configurations.
   */
  public function __construct() {
    // No openai_embeddings config loading here.
  }

  /**
   * Initialize an HTTP client with default options.
   *
   * @param array $options
   *   Additional options for the HTTP client.
   *
   * @return \GuzzleHttp\Client
   *   A configured Guzzle HTTP client.
   */
  public function getHttpClient(array $options = []): GuzzleClient {
    $default_options = [
      'headers' => ['Content-Type' => 'application/json'],
    ];
    return new GuzzleClient(array_merge($default_options, $options));
  }

  /**
   * Log payload data for debugging purposes.
   *
   * @param string $operation
   *   The operation being performed (e.g., 'query', 'upsert').
   * @param array  $payload
   *   The payload data to log.
   */
  protected function logPayload(string $operation, array $payload): void {
    watchdog('search_api_ai', "Payload for $operation: @payload", [
      '@payload' => json_encode($payload, JSON_PRETTY_PRINT),
    ], WATCHDOG_DEBUG);
  }

  /**
   * Log response data for debugging purposes.
   *
   * @param string $operation
   *   The operation being performed (e.g., 'query', 'upsert').
   * @param mixed  $response
   *   The response data to log.
   */
  protected function logResponse(string $operation, $response): void {
    watchdog('search_api_ai', "Response from $operation: @response", [
      '@response' => json_encode($response, JSON_PRETTY_PRINT),
    ], WATCHDOG_DEBUG);
  }

  /**
   * Handle exceptions and log errors.
   *
   * @param string $operation
   *   The operation being performed (e.g., 'query', 'upsert').
   * @param \Exception $e
   *   The exception to handle.
   *
   * @throws \Exception
   *   Re-throws the exception after logging it.
   */
  protected function handleError(string $operation, \Exception $e): void {
    $message = $e instanceof \GuzzleHttp\Exception\RequestException
    && $e->hasResponse()
      ? $e->getResponse()->getBody()->getContents()
      : $e->getMessage();

    watchdog('search_api_ai', "Error during $operation: @message", [
      '@message' => $message,
    ], WATCHDOG_ERROR);

    throw $e;
  }

  /**
   * Abstract methods to be implemented by child classes.
   */
  abstract public function query(array $parameters);

  abstract public function upsert(array $parameters);

  abstract public function stats();

}

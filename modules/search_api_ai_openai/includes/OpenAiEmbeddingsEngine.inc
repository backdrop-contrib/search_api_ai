<?php

/**
 * @file
 * OpenAI embedding engine implementation used for dynamic model registration.
 */
class OpenAiEmbeddingsEngine extends SearchApiAiEmbeddingsBase {

  /**
   * Constructs the engine.
   *
   * @param array $config
   *   Configuration including model metadata.
   * @param object|null $client
   *   Optional client instance; falls back to openai_get_api('openai').
   */
  public function __construct(array $config = [], $client = NULL) {
    if ($client) {
      parent::__construct($config, $client);
    }
    else {
      $stripped = NULL;
      $default_client = search_api_ai_openai_client_for_model('openai', $stripped);
      parent::__construct($config, $default_client);
    }
  }

  /**
   * Generate embeddings.
   */
  public function generateEmbeddings($text, $options = array()) {
    if (!$this->client) {
      throw new Exception('OpenAI client is not available.');
    }
    return parent::generateEmbeddings($text, $options);
  }
}


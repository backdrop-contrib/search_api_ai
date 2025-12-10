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
    parent::__construct($config, $client ?: openai_get_api('openai'));
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


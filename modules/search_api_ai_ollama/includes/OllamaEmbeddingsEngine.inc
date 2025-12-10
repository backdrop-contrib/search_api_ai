<?php

/**
 * @file
 * Ollama Embeddings Engine for Search API AI.
 */

/**
 * Ollama embedding engine implementation.
 * Extends OpenAiEmbeddingsDefault for compatibility with Search API AI.
 */
class OllamaEmbeddingsEngine extends SearchApiAiEmbeddingsBase {

  /**
   * The configuration.
   *
   * @var array
   */
  protected $config;

  /**
   * The Ollama API client.
   *
   * @var object
   */
  protected $client;

  /**
   * The model name/ID.
   *
   * @var string
   */
  protected $modelName;

  /**
   * The dimension of the embeddings (dynamically detected).
   *
   * @var int|null
   */
  protected $modelDimension = NULL;

  /**
   * Constructs a new OllamaEmbeddingsEngine.
   *
   * @param array $config
   *   The configuration including model_id.
   * @param object $client
   *   Not used - we get our own Ollama client.
   */
  public function __construct(array $config, $client = NULL) {
    $this->config = $config;
    $this->modelName = !empty($config['model_id']) ? $config['model_id'] : 'nomic-embed-text';

    watchdog('search_api_ai_ollama', 'OllamaEmbeddingsEngine initialized with model: @model', array(
      '@model' => $this->modelName,
    ), WATCHDOG_DEBUG);

    // Get Ollama-specific client
    $this->client = openai_get_api('ollama');

    if (!$this->client) {
      throw new Exception('Failed to initialize Ollama API client');
    }

    watchdog('search_api_ai_ollama', 'Ollama client class: @class', array(
      '@class' => get_class($this->client),
    ), WATCHDOG_DEBUG);
  }

  /**
   * Check if the embedding engine is available.
   *
   * @return bool
   *   TRUE if Ollama is available, FALSE otherwise.
   */
  public function isAvailable() {
    return !empty($this->client);
  }

  /**
   * Build the embedding configuration form.
   *
   * @return array
   *   The configuration form array.
   */
  public function buildEmbeddingConfigurationForm() {
    $form = array();

    $dimension = $this->getDimension();

    $form['dimension'] = array(
      '#type' => 'number',
      '#default_value' => $dimension,
      '#required' => TRUE,
      '#title' => t('Dimension'),
      '#description' => t('The dimension of the embeddings for this model. This is automatically detected but can be overridden.'),
    );

    $form['model_info'] = array(
      '#type' => 'item',
      '#markup' => '<p>' . t('Model: <strong>@model</strong>', array('@model' => $this->modelName)) . '</p>',
    );
    return $form;
  }

  /**
   * Generate embeddings for the given text.
   *
   * @param string $text
   *   The text to embed.
   * @param array $options
   *   Additional options (not used currently).
   *
   * @return array
   *   The generated embedding vector.
   */
  public function generateEmbeddings($text, $options = array()) {
    if (!$this->client) {
      watchdog('search_api_ai_ollama', 'Ollama client not initialized', array(), WATCHDOG_ERROR);
      return array();
    }

    try {
      watchdog('search_api_ai_ollama', 'Generating embeddings with Ollama model @model for text: @text', array(
        '@model' => $this->modelName,
        '@text' => substr($text, 0, 100),
      ), WATCHDOG_DEBUG);

      $result = $this->client->embedding($text, $this->modelName);

      watchdog('search_api_ai_ollama', 'Ollama returned @count dimensions', array(
        '@count' => is_array($result) ? count($result) : 'not an array',
      ), WATCHDOG_DEBUG);

      return $result;
    }
    catch (Exception $e) {
      watchdog('search_api_ai_ollama', 'Error generating embeddings with model @model: @error', array(
        '@model' => $this->modelName,
        '@error' => $e->getMessage(),
      ), WATCHDOG_ERROR);
      return array();
    }
  }

  /**
   * Get the embedding dimension for this model.
   *
   * @return int
   *   The dimension of the embeddings.
   */
  public function getDimension() {
    if ($this->modelDimension === NULL) {
      // Use dynamic detection from openai module
      $this->modelDimension = openai_get_embedding_dimension($this->modelName, 'ollama');
    }
    return $this->modelDimension;
  }

  /**
   * Get the model name.
   *
   * @return string
   *   The model name.
   */
  public function getModelName() {
    return $this->modelName;
  }
}

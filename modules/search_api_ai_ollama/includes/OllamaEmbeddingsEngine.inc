<?php

/**
 * @file
 * Ollama Embeddings Engine for Search API AI.
 */

/**
 * Ollama embedding engine implementation.
 * Extends SearchApiAiEmbeddingsBase for compatibility with Search API AI.
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

  // No explicit constructor: let parent constructor run so model metadata is initialized.
  // Resolve the provider client lazily when we need it (avoids constructor parent-call analyzer issues).

  /**
   * Ensure the Ollama client is resolved and assigned on $this->client.
   *
   * @return bool
   *   TRUE if a client is available, FALSE otherwise.
   */
  protected function ensureClient() {
    if (!empty($this->client)) {
      return TRUE;
    }
    $model = $this->getModelName();
    $stripped = NULL;
    $resolved = search_api_ai_openai_client_for_model('ollama/' . $model, $stripped);
    if ($resolved) {
      $this->client = $resolved;
      watchdog('search_api_ai_ollama', 'Resolved Ollama client for model @model: @class', ['@model' => $model, '@class' => get_class($resolved)], WATCHDOG_DEBUG);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Check if the embedding engine is available.
   *
   * @return bool
   *   TRUE if Ollama is available, FALSE otherwise.
   */
  public function isAvailable() {
    return $this->ensureClient();
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
    if (!$this->ensureClient()) {
      watchdog('search_api_ai_ollama', 'Ollama client not initialized', array(), WATCHDOG_ERROR);
      return array();
    }

    try {
      watchdog('search_api_ai_ollama', 'Generating embeddings with Ollama model @model for text: @text', array(
        '@model' => $this->modelName,
        '@text' => substr($text, 0, 100),
      ), WATCHDOG_DEBUG);

      // Use compatibility wrapper to request embeddings (supports many client shapes).
      $result = search_api_ai_openai_call_embedding($this->client, $text, $this->modelName);

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
      // Prefer new helper if available
      if (function_exists('openai_get_embedding_dimension')) {
        $this->modelDimension = openai_get_embedding_dimension($this->modelName, 'ollama');
      }
      else {
        // Fallback: attempt to query the client for dimension information
        $this->modelDimension = search_api_ai_get_embedding_dimension();
      }
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

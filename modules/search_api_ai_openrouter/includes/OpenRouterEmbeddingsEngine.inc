<?php

/**
 * @file
 * OpenRouter Embeddings Engine for Search API AI.
 */

/**
 * OpenRouter embedding engine implementation.
 */
class OpenRouterEmbeddingsEngine extends SearchApiAiEmbeddingsBase {

  /**
   * The configuration.
   *
   * @var array
   */
  protected $config;

  /**
   * The OpenRouter API client.
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
   * Constructs a new OpenRouterEmbeddingsEngine.
   *
   * @param array $config
   *   The configuration including model_id.
   * @param object $client
   *   Not used - we get our own OpenRouter client.
   */
  public function __construct(array $config, $client = NULL) {
    $this->config = $config;
    $this->modelName = !empty($config['model_id']) ? $config['model_id'] : 'voyage-ai/voyage-3';

    // Resolve OpenRouter client via compatibility helper.
    // Important: we resolve the client first and explicitly throw if
    // resolution fails. This guarantees we never call the parent
    // constructor with a NULL client. Order: (1) resolve client,
    // (2) validate/throw on failure, (3) call parent with the valid
    // client so base class has the dependency it expects.
    $stripped = NULL;
    $this->client = search_api_ai_openai_client_for_model('openrouter/' . $this->modelName, $stripped);
    if (!$this->client) {
      throw new Exception('Failed to initialize OpenRouter API client');
    }

    // Initialize parent with resolved client for base functionality.
    parent::__construct($config, $this->client);
  }

  /**
   * Check if the embedding engine is available.
   *
   * @return bool
   *   TRUE if OpenRouter is available, FALSE otherwise.
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
    try {
      return search_api_ai_openai_call_embedding($this->client, $text, $this->modelName);
    }
    catch (Exception $e) {
      watchdog('search_api_ai_openrouter', 'Error generating embeddings with model @model: @error', array(
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
      if (function_exists('openai_get_embedding_dimension')) {
        $this->modelDimension = openai_get_embedding_dimension($this->modelName, 'openrouter');
      }
      else {
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

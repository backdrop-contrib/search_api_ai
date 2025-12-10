<?php

/**
 * Base class for Search API AI embedding engines.
 */
abstract class SearchApiAiEmbeddingsBase {

  /**
   * The configuration.
   *
   * @var array
   */
  protected $config;

  /**
   * The client.
   *
   * @var object
   */
  protected $client;

  /**
   * The dimension of the embeddings.
   *
   * @var int
   */
  protected $modelDimension = 1536;

  /**
   * The name of the model.
   *
   * @var string
   */
  protected $modelName = 'text-embedding-ada-002';

  /**
   * Constructs a new instance.
   *
   * @param array $config
   *   The configuration.
   * @param object $client
   *   The client.
   */
  public function __construct(array $config = [], $client = NULL) {
    $this->config = $config;
    $this->client = $client;
    $this->initializeModelMetadata($config);
  }

  /**
   * Initialize metadata such as the model name and dimension.
   */
  protected function initializeModelMetadata(array $config) {
    if (!empty($config['model_name'])) {
      $this->modelName = (string) $config['model_name'];
    }
    elseif (!empty($config['model_id'])) {
      $this->modelName = (string) $config['model_id'];
    }

    if (isset($config['model_dimension'])) {
      $this->modelDimension = (int) $config['model_dimension'];
    }
    elseif (isset($config['dimension'])) {
      $this->modelDimension = (int) $config['dimension'];
    }
  }

  /**
   * Check if the embedding engine is available.
   *
   * @return bool
   *   TRUE if available, FALSE otherwise.
   */
  public function isAvailable() {
    return TRUE;
  }

  /**
   * Build the embedding configuration form.
   *
   * @return array
   *   The configuration form array.
   */
  public function buildEmbeddingConfigurationForm() {
    $form = array();
    $form['dimension'] = array(
      '#type' => 'number',
      '#default_value' => $this->modelDimension,
      '#required' => TRUE,
      '#title' => t('Dimension'),
      '#description' => t('The dimension of the embeddings. Can be under @dimension.', array(
        '@dimension' => $this->modelDimension,
      )),
    );
    return $form;
  }

  /**
   * Generate embeddings for the given text.
   *
   * @param string $text
   *   The text to embed.
   * @param array $options
   *   Additional options.
   *
   * @return array
   *   The generated embeddings.
   */
  public function generateEmbeddings($text, $options = array()) {
    // Directly call the embedding method with required parameters
    $model = $this->modelName;

    // The client->embedding() method handles the embedding generation
    // with the proper parameters (input and model)
    $query_embedding = $this->client->embedding($text, $model);

    return $query_embedding;
  }

  /**
   * Get the dimension of the embeddings.
   *
   * @return int
   *   The embedding dimension.
   */
  public function getDimension() {
    return !empty($this->config['dimension']) ? $this->config['dimension'] : $this->modelDimension;
  }

  /**
   * Returns the model name.
   *
   * @return string
   *   The model name.
   */
  public function getModelName() {
    return $this->modelName;
  }
}

<?php

/**
 * Base class for OpenAI embedding engines.
 */
abstract class OpenAiEmbeddingsDefault {

  /**
   * The configuration.
   *
   * @var array
   */
  protected $config;

  /**
   * The OpenAI client.
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
   * Constructs a new OpenAI instance.
   *
   * @param array $config
   *   The configuration.
   * @param object $client
   *   The OpenAI client.
   */
  public function __construct(array $config, $client) {
    $this->config = $config;
    $this->client = $client;
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
    $embed_settings = array(
      'model' => $this->modelName,
      'input' => $text,
    );

    if (!empty($this->config['dimension'])) {
      $embed_settings['dimension'] = $this->config['dimension'];
    }

    // Assuming $this->client is a valid OpenAI client instance.
    $response = $this->client->embeddings()->create($embed_settings)->toArray();
    $query_embedding = isset($response['data'][0]['embedding']) ? $response['data'][0]['embedding'] : NULL;

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


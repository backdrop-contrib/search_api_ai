<?php

namespace Drupal\search_api_ai_openai;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use OpenAI\Client;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The OpenAI embedding defaule for engines.
 */
abstract class OpenAiEmbeddingsDefault implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * The dimension of the embeddings.
   */
  protected int $modelDimension = 3072;

  /**
   * The name of the model.
   */
  protected string $modelName = 'text-davinci-003';

  /**
   * Constructs a new OpenAi instance.
   *
   * @param array $config
   *   The configuration.
   * @param \OpenAI\Client $client
   *   The OpenAI client.
   */
  public function __construct(
    private array $config,
    private Client $client,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $container->get('openai.client'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function buildEmbeddingConfigurationForm(): array {
    $form['dimension'] = [
      '#type' => 'number',
      '#default_value' => $this->modelDimension,
      '#required' => TRUE,
      '#title' => $this->t('Dimension'),
      '#description' => $this->t('The dimension of the embeddings. Can be under %dimension.', [
        '%dimension' => $this->modelDimension,
      ]),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function generateEmbeddings(string $text, array $options = []): array {
    $embedSettings = [
      'model' => $this->modelName,
      'input' => $text,
    ];
    if ($this->config['dimension']) {
      $embedSettings['dimension'] = $this->config['dimension'];
    }
    $response = $this->client->embeddings()->create($embedSettings)->toArray();
    $query_embedding = $response['data'][0]['embedding'] ?? NULL;
    return $query_embedding;
  }

  /**
   * {@inheritdoc}
   */
  public function getDimension(): int {
    return $this->config['dimension'] ?? $this->modelDimension;
  }

}

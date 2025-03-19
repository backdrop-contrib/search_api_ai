<?php

namespace Drupal\search_api_ai\Plugin\search_api\data_type;

use Drupal\openai\Utility\StringHelper;
use Drupal\search_api\DataType\DataTypePluginBase;
use Drupal\search_api_ai\EmbeddingEngineStatic;
use Drupal\search_api_ai\TextChunker;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the embeddings data type.
 *
 * @SearchApiDataType(
 *   id = "embeddings",
 *   label = @Translation("Embeddings"),
 *   description = @Translation("LLM Vector Embeddings")
 * )
 */
class Embeddings extends DataTypePluginBase {

  /**
   * Embeddings engine.
   *
   * @var \Drupal\search_api_ai\EmbeddingEngineInterface
   *   The embeddings engine.
   */
  protected $embeddingsEngine;

  /**
   * Constructor.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EmbeddingEngineStatic $embeddingEngineStatic) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    // If the configuration is empty, retrieve it from settings.
    if (empty($configuration['embeddings_engine']) || empty($configuration['embeddings_engine_configuration'])) {
      $config = \Drupal::config('search_api_ai.settings');
      $configuration['embeddings_engine'] = $config->get('embeddings_engine');
      $configuration['embeddings_engine_configuration'] = $config->get('embeddings_engine_configuration');
    }

    // Debugging: Ensure constructor gets correct values.
    /*dpm([
      'Constructor Engine' => $configuration['embeddings_engine'] ?? 'MISSING',
      'Constructor Config' => $configuration['embeddings_engine_configuration'] ?? 'MISSING',
    ]);*/

    // Ensure the engine is instantiated with a valid configuration.
    if (!empty($configuration['embeddings_engine'])) {
      $this->embeddingEngine = \Drupal::service('plugin.manager.embedding_engine')
        ->createInstance($configuration['embeddings_engine'], $configuration['embeddings_engine_configuration']);
    }
    else {
      // Load from static storage as a fallback.
      $this->embeddingEngine = $embeddingEngineStatic->getEmbeddingEngine();
    }
  }

  /**
   * Load from dependency injection container.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('search_api_ai.embedding_engine_static')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getValue($value) {
    // Ensure configuration exists.
    dpm([
      'Loaded Engine in getValue' => $this->configuration['embeddings_engine'] ?? 'MISSING',
      'Loaded Config in getValue' => $this->configuration['embeddings_engine_configuration'] ?? 'MISSING',
    ]);

    // If still missing, force reload from settings.
    if (empty($this->configuration['embeddings_engine']) || empty($this->configuration['embeddings_engine_configuration'])) {
      $config = \Drupal::config('search_api_ai.settings');
      $this->configuration['embeddings_engine'] = $config->get('embeddings_engine');
      $this->configuration['embeddings_engine_configuration'] = $config->get('embeddings_engine_configuration');
    }

    // Ensure engine and configuration exist before proceeding.
    if (empty($this->configuration['embeddings_engine'])) {
      \Drupal::logger('search_api_ai')->error('No embeddings engine set.');
      return [];
    }

    // Create the embedding engine.
    try {
      $plugin_manager = \Drupal::service('plugin.manager.embedding_engine');
      $this->embeddingEngine = $plugin_manager->createInstance(
        $this->configuration['embeddings_engine'],
        $this->configuration['embeddings_engine_configuration']
      );
    }
    catch (\Exception $e) {
      \Drupal::logger('search_api_ai')->error('Failed to initialize embedding engine: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }

    // Retrieve the dimension correctly.
    $chunkMaxSize = $this->embeddingEngine->getDimension();
    dpm([
      'Final Engine ID' => $this->configuration['embeddings_engine'],
      'Final Dimension' => $chunkMaxSize,
    ]);

    // Process text for embeddings.
    $chunkMinOverlap = 64;
    $chunks = TextChunker::chunkText($value, $chunkMaxSize, $chunkMinOverlap);
    $items = [];

    foreach ($chunks as $delta => $chunk) {
      if (!mb_strlen($chunk)) {
        continue;
      }

      $text = StringHelper::prepareText($chunk, [], $chunkMaxSize);
      $vectors = $this->embeddingEngine->generateEmbeddings($text);

      if (is_array($vectors)) {
        $items[$delta] = [
          'content' => $text,
          'vectors' => $vectors,
        ];
      }
    }

    return $items;
  }

}

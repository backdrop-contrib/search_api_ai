<?php

namespace Drupal\search_api_ai\Plugin\search_api\data_type;

use Drupal\openai\Utility\StringHelper;
use Drupal\search_api\DataType\DataTypePluginBase;
use Drupal\search_api_ai\TextChunker;
use OpenAI\Client;
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
   * The OpenAI Client.
   *
   * @var \OpenAI\Client
   *
   * @todo Abstract the embedding engine.
   */
  protected Client $client;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $plugin = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $plugin->client = $container->get('openai.client');
    return $plugin;
  }

  /**
   * {@inheritdoc}
   */
  public function getValue($value) {
    // @todo Make configurable.
    $chunkMaxSize = 1536;
    $chunkMinOverlap = 64;
    $model = 'text-embedding-ada-002';

    $chunks = TextChunker::chunkText($value, $chunkMaxSize, $chunkMinOverlap);

    $items = [];
    foreach ($chunks as $delta => $chunk) {
      // Ignore empty strings.
      if (!mb_strlen($chunk)) {
        continue;
      }

      $text = StringHelper::prepareText($chunk, [], $chunkMaxSize);

      $response = $this->client->embeddings()->create([
        'model' => $model,
        'input' => $text,
      ]);

      $items[$delta] = [
        'content' => $text,
        'vectors' => $response->toArray()['data'][0]['embedding'],
      ];
    }

    return $items;
  }

}

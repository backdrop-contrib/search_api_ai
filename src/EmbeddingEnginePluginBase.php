<?php

declare(strict_types=1);

namespace Drupal\search_api_ai;

use Drupal\Component\Plugin\PluginBase;

/**
 * Base class for embedding engine plugins.
 */
abstract class EmbeddingEnginePluginBase extends PluginBase implements EmbeddingEngineInterface {

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function generateEmbeddings(string $text, array $options = []): array {
    return [];
  }

}

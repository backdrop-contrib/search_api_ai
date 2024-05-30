<?php

declare(strict_types=1);

namespace Drupal\search_api_ai;

/**
 * Interface for embedding_engine plugins.
 */
interface EmbeddingEngineInterface {

  /**
   * Is available. Can do extra checks if this engine is available.
   *
   * @return bool
   *   TRUE if the engine is available, FALSE otherwise.
   */
  public function isAvailable(): bool;

  /**
   * Add extra configuration form.
   *
   * @return array
   *   The form array.
   */
  public function buildEmbeddingConfigurationForm(): array;

  /**
   * Generates embeddings for the given text.
   *
   * @param string $text
   *   The text to generate embeddings for.
   * @param array $options
   *   Additional options.
   *
   * @return array
   *   The embeddings.
   */
  public function generateEmbeddings(string $text, array $options = []): array;

  /**
   * Get dimension of the embeddings.
   *
   * @return int
   *   The dimension of the embeddings.
   */
  public function getDimension(): int;

}

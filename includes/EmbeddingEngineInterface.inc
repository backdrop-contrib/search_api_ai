<?php

/**
 * Interface for embedding engine plugins.
 */
interface EmbeddingEngineInterface {

  /**
   * Check if this embedding engine is available.
   *
   * @return bool
   *   TRUE if the engine is available, FALSE otherwise.
   */
  public function isAvailable();

  /**
   * Build extra configuration form for the embedding engine.
   *
   * @return array
   *   The configuration form array.
   */
  public function buildEmbeddingConfigurationForm();

  /**
   * Generate embeddings for the provided text.
   *
   * @param string $text
   *   The text to generate embeddings for.
   * @param array $options
   *   Additional options for embedding generation.
   *
   * @return array
   *   The generated embeddings.
   */
  public function generateEmbeddings($text, $options = array());

  /**
   * Get the dimension of the embeddings.
   *
   * @return int
   *   The dimension of the generated embeddings.
   */
  public function getDimension();
}

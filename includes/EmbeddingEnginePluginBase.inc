<?php

/**
 * Base class for embedding engine plugins.
 */
abstract class EmbeddingEnginePluginBase implements EmbeddingEngineInterface {

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
   * Generate embeddings for the provided text.
   *
   * @param string $text
   *   The text to generate embeddings for.
   * @param array $options
   *   Additional options for generating embeddings.
   *
   * @return array
   *   The generated embeddings.
   */
  public function generateEmbeddings($text, $options = array()) {
    return array();
  }
}

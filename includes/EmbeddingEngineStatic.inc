<?php

/**
 * Static context for the embedding engines.
 *
 * Since the data type plugins have no context of where they are being used,
 * we need to use a static class to temporarily store the embed engine during
 * indexing.
 */
class EmbeddingEngineStatic {

  /**
   * The embedding engine object.
   *
   * @var mixed
   */
  protected $embeddingEngine;

  /**
   * Sets the embedding engine object.
   *
   * @param mixed $object
   *   The embedding engine object.
   */
  public function setEmbeddingEngine($object) {
    watchdog('search_api_ai', 'Setting embedding engine: @engine', [
      '@engine' => is_object($object) ? get_class($object) : (is_null($object) ? 'NULL' : gettype($object)),
    ], WATCHDOG_DEBUG);

    $this->embeddingEngine = $object;
  }

  /**
   * Gets the embedding engine object.
   *
   * @return mixed
   *   The embedding engine object.
   */
  public function getEmbeddingEngine() {
    watchdog('search_api_ai', 'Getting embedding engine: @engine', [
      '@engine' => is_object($this->embeddingEngine) ? get_class($this->embeddingEngine) : (is_null($this->embeddingEngine) ? 'NULL' : gettype($this->embeddingEngine)),
    ], WATCHDOG_DEBUG);

    return $this->embeddingEngine;
  }
}

<?php

namespace Drupal\search_api_ai;

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
   * Sets the embeddinge engine object.
   *
   * @param mixed $object
   *   The embedding engine object.
   */
  public function setEmbeddingEngine($object) {
    \Drupal::logger('search_api_ai')->debug('Setting embedding engine: @engine', [
      '@engine' => is_object($object) ? get_class($object) : (is_null($object) ? 'NULL' : gettype($object)),
    ]);
    $this->embeddingEngine = $object;
  }


  /**
   * Gets the embedding engine object.
   *
   * @return mixed
   *   The embedding engine object.
   */
  public function getEmbeddingEngine() {
    \Drupal::logger('search_api_ai')->debug('Getting embedding engine: @engine', [
      '@engine' => is_object($this->embeddingEngine) ? get_class($this->embeddingEngine) : (is_null($this->embeddingEngine) ? 'NULL' : gettype($this->embeddingEngine)),
    ]);
    dpm($this->embeddingEngine);
    return $this->embeddingEngine;
  }


}

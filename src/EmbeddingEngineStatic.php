<?php

namespace Drupal\search_api_ai;

/**
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
    $this->embeddingEngine = $object;
  }

  /**
   * Gets the embedding engine object.
   *
   * @return mixed
   *   The embedding engine object.
   */
  public function getEmbeddingEngine() {
    return $this->embeddingEngine;
  }

}

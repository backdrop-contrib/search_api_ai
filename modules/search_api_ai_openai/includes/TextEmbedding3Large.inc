<?php

/**
 * OpenAI Text Embedding 3 Large.
 */
class TextEmbedding3Large extends OpenAiEmbeddingsDefault {

  /**
   * The dimension of the embeddings.
   *
   * @var int
   */
  protected $modelDimension = 3072;

  /**
   * The name of the model.
   *
   * @var string
   */
  protected $modelName = 'text-embedding-3-large';
}

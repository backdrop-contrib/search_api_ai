<?php

/**
 * OpenAI Text Embedding Ada 002.
 */
class TextEmbeddingAda002 extends OpenAiEmbeddingsDefault {

  /**
   * The dimension of the embeddings.
   *
   * @var int
   */
  protected $modelDimension = 1536;

  /**
   * The name of the model.
   *
   * @var string
   */
  protected $modelName = 'text-embedding-ada-002';
}
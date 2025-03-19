<?php

/**
 * OpenAI Text Embedding 3 Small.
 */
class TextEmbedding3Small extends OpenAiEmbeddingsDefault {

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
  protected $modelName = 'text-embedding-3-small';
}

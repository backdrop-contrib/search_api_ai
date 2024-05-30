<?php

namespace Drupal\search_api_ai_openai\Plugin\EmbeddingEngine;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\search_api_ai\Attribute\EmbeddingEngine;
use Drupal\search_api_ai\EmbeddingEngineInterface;
use Drupal\search_api_ai_openai\OpenAiEmbeddingsDefault;

/**
 * The OpenAI embedding engine.
 */
#[EmbeddingEngine(
  id: 'openai_text_embedding_3_large',
  label: new TranslatableMarkup('OpenAI Text Embedding 3 Large'),
  description: new TranslatableMarkup('The larger of the generation 3 models.'),
  dimension: 3072,
)]
final class TextEmbedding3Large extends OpenAiEmbeddingsDefault implements EmbeddingEngineInterface {

  /**
   * {@inheritdoc}
   */
  protected int $modelDimension = 3072;

  /**
   * {@inheritdoc}
   */
  protected string $modelName = 'text-embedding-3-large';

}

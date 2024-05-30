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
  id: 'openai_text_embedding_3_small',
  label: new TranslatableMarkup('OpenAI Text Embedding 3 Small'),
  description: new TranslatableMarkup('The smaller of the generation 3 models.'),
  dimension: 1536,
)]
final class TextEmbedding3Small extends OpenAiEmbeddingsDefault implements EmbeddingEngineInterface {

  /**
   * {@inheritdoc}
   */
  protected int $modelDimension = 1536;

  /**
   * {@inheritdoc}
   */
  protected string $modelName = 'text-embedding-3-small';

}

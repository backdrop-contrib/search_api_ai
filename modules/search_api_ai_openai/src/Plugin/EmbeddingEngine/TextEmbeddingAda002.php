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
  id: 'openai_text_embedding_ada_002',
  label: new TranslatableMarkup('OpenAI Text Embedding Ada 002'),
  description: new TranslatableMarkup('The old legacy Ada 002.'),
  dimension: 1536,
)]
final class TextEmbeddingAda002 extends OpenAiEmbeddingsDefault implements EmbeddingEngineInterface {

    /**
     * {@inheritdoc}
     */
    protected int $modelDimension = 1536;

    /**
     * {@inheritdoc}
     */
    protected string $modelName = 'text-embedding-ada-002';

}

<?php

/**
 * Implements hook_embedding_engine_info().
 *
 * Provides metadata for embedding engine plugins.
 */
function search_api_ai_embedding_engine_info() {
  return array(
    'default_embedding_engine' => array(
      'label' => t('Default Embedding Engine'),
      'description' => t('Provides default embeddings for text.'),
      'dimension' => 768,
      'class' => 'DefaultEmbeddingEngine',
    ),
    // Add more engines here.
  );
}

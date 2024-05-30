<?php

declare(strict_types=1);

namespace Drupal\search_api_ai;

use Drupal\Core\Form\FormStateInterface;

/**
 * Interface for search api ai backends plugins.
 */
interface SearchApiAiBackendInterface {

  /**
   * Load the embeddings engine with a configuration.
   *
   * @return \Drupal\search_api_ai\EmbeddingEngineInterface
   *   The embeddings engine.
   */
  public function loadEmbeddingsEngine();

  /**
   * Returns the embeddings engine.
   *
   * @return string
   *   The embeddings engine.
   */
  public function getEmbeddingsEngine(): string;

  /**
   * Returns all available embedding engines as options.
   *
   * @return array
   *   The embedding engines.
   */
  public function getEmbeddingEnginesOptions(): array;

  /**
   * Callback to update the embedding configuration form.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The updated form.
   */
  public function updateEmbeddingConfigurationForm(array $form, FormStateInterface $form_state);

}

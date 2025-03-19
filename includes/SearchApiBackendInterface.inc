<?php

/**
 * Interface for Search API AI backends plugins.
 */
interface SearchApiAiBackendInterface {

  /**
   * Load the embeddings engine with a configuration.
   *
   * @return EmbeddingEngineInterface
   *   The embeddings engine.
   */
  public function loadEmbeddingsEngine();

  /**
   * Returns the embeddings engine.
   *
   * @return string
   *   The embeddings engine.
   */
  public function getEmbeddingsEngine();

  /**
   * Returns all available embedding engines as options.
   *
   * @return array
   *   The embedding engines.
   */
  public function getEmbeddingEnginesOptions();

  /**
   * Callback to update the embedding configuration form.
   *
   * @param array $form
   *   The form array.
   * @param array $form_state
   *   The form state array.
   *
   * @return array
   *   The updated form.
   */
  public function updateEmbeddingConfigurationForm(array $form, array &$form_state);
}

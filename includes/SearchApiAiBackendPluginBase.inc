<?php

/**
 * Base class for Search API AI backend plugins.
 *
 * This class adds logic for loading and storing embedding engines and
 * provides default implementations for configuration forms.
 */
abstract class SearchApiAiBackendPluginBase {

  protected $configuration;

  /**
   * Constructor.
   *
   * @param array $configuration
   *   Configuration settings for the backend.
   */
  public function __construct($configuration = array()) {
    $this->configuration = $configuration;
  }

  /**
   * Provides default configuration for the embedding engine.
   *
   * @return array
   *   Default configuration settings.
   */
  public function defaultConfiguration() {
    return $this->defaultEngineConfiguration();
  }

  /**
   * Builds the configuration form for the embedding engine.
   *
   * @param array $form
   *   The form array.
   * @param array $form_state
   *   The form state array.
   *
   * @return array
   *   The modified form array.
   */
  public function buildConfigurationForm($form, &$form_state) {
    $this->setEngineConfiguration($this->configuration);
    return $this->engineConfigurationForm($form, $form_state);
  }

  /**
   * Provides default engine configuration settings.
   *
   * @return array
   *   Default engine configuration.
   */
  protected function defaultEngineConfiguration() {
    return array(
      'embeddings_engine' => '',
      'embeddings_engine_configuration' => array('dimension' => 768),
    );
  }

  /**
   * Sets the engine configuration.
   *
   * @param array $configuration
   *   The configuration settings for the embedding engine.
   */
  protected function setEngineConfiguration($configuration) {
    $this->configuration = $configuration;
  }

  /**
   * Builds the embedding engine configuration form.
   *
   * @param array $form
   *   The form array.
   * @param array $form_state
   *   The form state array.
   *
   * @return array
   *   The updated form with embedding engine configuration fields.
   */
  protected function engineConfigurationForm($form, &$form_state) {
    $config = config_get('search_api_ai.settings');
    $selected_engine = isset($this->configuration['embeddings_engine'])
      ? $this->configuration['embeddings_engine']
      : $config['embeddings_engine'];

    $form['embeddings_engine'] = array(
      '#type' => 'textfield',
      '#title' => t('Embeddings Engine'),
      '#default_value' => $selected_engine,
      '#description' => t('Specify the embeddings engine to be used.'),
    );

    $form['embeddings_engine_configuration'] = array(
      '#type' => 'fieldset',
      '#title' => t('Embeddings Engine Configuration'),
    );

    $form['embeddings_engine_configuration']['dimension'] = array(
      '#type' => 'textfield',
      '#title' => t('Embedding Dimension'),
      '#default_value' => isset($this->configuration['embeddings_engine_configuration']['dimension'])
        ? $this->configuration['embeddings_engine_configuration']['dimension']
        : 768,
      '#description' => t('The dimension of the embeddings.'),
    );

    return $form;
  }
}

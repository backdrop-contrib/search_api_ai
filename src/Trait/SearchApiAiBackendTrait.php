<?php

namespace Drupal\search_api_ai\Trait;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Trait for Search API AI.
 *
 * This will add some of the function that are not needed for the interface
 * with empty implementations. This adds logic for loading and storing
 * embedding engines.
 */
trait SearchApiAiBackendTrait {

  use StringTranslationTrait;

  /**
   * The configuration.
   *
   * @var array
   */
  protected $traitConfiguration = [];

  /**
   * Sets the configuration.
   *
   * @param array $configuration
   *   The configuration.
   */
  public function setEngineConfiguration(array $configuration) {
    $this->traitConfiguration = $configuration;
  }

  /**
   * Set the embeddings engine configuration.
   *
   * @return array
   *   The configuration.
   */
  public function defaultEngineConfiguration() {
    return [
      'embeddings_engine' => NULL,
      'embeddings_engine_configuration' => [
        'dimensions' => 768,
      ],
    ];
  }

  /**
   * Builds the engine part of the configuration form.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form array.
   */
  public function engineConfigurationForm(array $form, FormStateInterface $form_state) {
    // Ensure form state gets the updated dimensions.
    $dimension_value = $form_state->get('embeddings_engine_configuration')['dimension']
      ?? $this->traitConfiguration['embeddings_engine_configuration']['dimension']
      ?? 768; // Default fallback

    // Embeddings Engine dropdown.
    $form['embeddings_engine'] = [
      '#type' => 'select',
      '#title' => $this->t('Embeddings Engine'),
      '#options' => $this->getEmbeddingEnginesOptions(),
      '#required' => TRUE,
      '#default_value' => $this->traitConfiguration['embeddings_engine'],
      '#description' => $this->t('The service to use for embeddings. If you change this, everything will need to be reindexed.'),
      '#weight' => 10,
      '#ajax' => [
        'callback' => [$this, 'updateEmbeddingConfigurationForm'],
        'wrapper' => 'embedding-configuration-wrapper',
        'method' => 'replace',
        'effect' => 'fade',
      ],
    ];

    // Configuration details.
    $form['embeddings_engine_configuration'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('Embeddings Engine Configuration'),
      '#prefix' => '<div id="embedding-configuration-wrapper">',
      '#suffix' => '</div>',
      '#weight' => 20,
    ];

    // Dimensions field should pull from updated form state.
    $form['embeddings_engine_configuration']['dimension'] = [
      '#type' => 'number',
      '#title' => $this->t('Dimensions'),
      '#description' => $this->t('The number of dimensions for the embeddings.'),
      '#default_value' => $dimension_value,
      '#required' => TRUE,
      '#disabled' => TRUE,
    ];

    return $form;
  }

  /**
   * Load the embeddings engine with a configuration.
   *
   * @return \Drupal\search_api_ai\EmbeddingEngineInterface
   *   The embeddings engine.
   */
  public function loadEmbeddingsEngine() {
    $plugin_manager = \Drupal::service('plugin.manager.embedding_engine');
    return $plugin_manager->createInstance($this->traitConfiguration['embeddings_engine'], $this->traitConfiguration['embeddings_engine_configuration']);
  }

  /**
   * Returns the embeddings engine.
   *
   * @return string
   *   The embeddings engine.
   */
  public function getEmbeddingsEngine(): string {
    return $this->traitConfiguration['embeddings_engine'];
  }

  /**
   * Returns all available embedding engines as options.
   *
   * @return array
   *   The embedding engines.
   */
  public function getEmbeddingEnginesOptions(): array {
    $options = [];
    $plugin_manager = \Drupal::service('plugin.manager.embedding_engine');
    foreach ($plugin_manager->getDefinitions() as $id => $definition) {
      $rule = $plugin_manager->createInstance($id);
      if ($rule->isAvailable()) {
        $options[$id] = $definition['label'];
      }
    }
    // Send a warning message if there are no available embedding engines.
    if (empty($options)) {
      \Drupal::messenger()->addWarning('No embedding engines available. Please install and enable an embedding engine module before continuing.');
    }
    return $options;
  }

  /**
   * AJAX callback to update the embedding engine configuration form.
   */
  public function updateEmbeddingConfigurationForm(array &$form, FormStateInterface $form_state) {
    $selected_engine = $form_state->getValue('embeddings_engine');

    if ($selected_engine) {
      $plugin_manager = \Drupal::service('plugin.manager.embedding_engine');
      try {
        $engine_instance = $plugin_manager->createInstance($selected_engine);
        $dimension_value = $engine_instance->getDimension(); // Use modelDimension

        // Store the new dimension in form state.
        $form_state->set('embeddings_engine_configuration', ['dimension' => $dimension_value]);

        // Debugging to confirm correct values.
        /*dpm([
          'AJAX Updated Engine' => $selected_engine,
          'AJAX Updated Dimension' => $dimension_value,
        ]);*/
      }
      catch (\Exception $e) {
        \Drupal::messenger()->addError($this->t('Failed to load embedding engine configuration: @message', [
          '@message' => $e->getMessage(),
        ]));
      }
    }

    // Ensure only the relevant section updates.
    return $form['embeddings_engine_configuration'];
  }
}

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
    // It might be a Sub form state, so we need to get the complete form state.
    if ($form_state instanceof SubformStateInterface) {
      $form_state = $form_state->getCompleteFormState();
    }

    $form['embeddings_engine'] = [
      '#type' => 'select',
      '#title' => $this->t('Embeddings Engine'),
      '#options' => $this->getEmbeddingEnginesOptions(),
      '#required' => TRUE,
      '#default_value' => $this->traitConfiguration['embeddings_engine'],
      '#description' => $this->t('The service to use for embeddings. If you change this, everything will be needed to be reindexed.'),
      '#weight' => 10,
      // Callback to update the form on Ajax.
      '#ajax' => [
        'callback' => [$this, 'updateEmbeddingConfigurationForm'],
        'wrapper' => 'embedding-configuration-wrapper',
        'method' => 'replace',
        'effect' => 'fade',
      ],
    ];

    $form['embeddings_engine_configuration'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => 'Embeddings Engine Configuration',
      '#prefix' => '<div id="embedding-configuration-wrapper">',
      '#suffix' => '</div>',
      '#weight' => 20,
    ];

    $form['embeddings_engine_configuration']['dimension'] = [
      '#type' => 'number',
      '#title' => $this->t('Dimensions'),
      '#description' => $this->t('The number of dimensions for the embeddings.'),
      '#default_value' => $this->traitConfiguration['embeddings_engine_configuration']['dimension'],
      '#required' => TRUE,
      '#disabled' => TRUE,
    ];

    // If the embeddings engine is set, add the configuration form.
    if (!empty($this->traitConfiguration['embeddings_engine']) || $form_state->get('embeddings_engine')) {
      $plugin_manager = \Drupal::service('plugin.manager.embedding_engine');
      $rule = $plugin_manager->createInstance($this->traitConfiguration['embeddings_engine'] ?? $form_state->get('embeddings_engine'));
      foreach ($rule->buildEmbeddingConfigurationForm() as $key => $value) {
        $form['embeddings_engine_configuration'][$key] = $value;
      }
    }

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
  public function updateEmbeddingConfigurationForm(array $form, FormStateInterface $form_state) {
    $rule = $form_state->getValues()['backend_config']['embeddings_engine'] ?? NULL;
    $form_state->set('embeddings_engine', $rule);
    return $form['backend_config']['embeddings_engine_configuration'];
  }
}

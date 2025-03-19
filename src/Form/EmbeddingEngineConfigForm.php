<?php

namespace Drupal\search_api_ai\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api_ai\Trait\SearchApiAiBackendTrait;

/**
 * Configuration form for selecting and configuring the embedding engine.
 */
class EmbeddingEngineConfigForm extends ConfigFormBase {
  use SearchApiAiBackendTrait;

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['search_api_ai.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'embedding_engine_config_form';
  }

  /**
   * Build the configuration form.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Load the current configuration.
    $config = $this->config('search_api_ai.settings');

    // Get the selected engine from the configuration.
    $selected_engine = $form_state->getValue('embeddings_engine') ?? $config->get('embeddings_engine');

    // Ensure the engine configuration is properly initialized.
    $engine_config = $config->get('embeddings_engine_configuration') ?? ['dimension' => 768];

    // Fetch dimensions dynamically if an engine is selected.
    if ($selected_engine) {
      $plugin_manager = \Drupal::service('plugin.manager.embedding_engine');
      try {
        $engine_instance = $plugin_manager->createInstance($selected_engine);
        $engine_config['dimension'] = $engine_instance->getDimension();
      }
      catch (\Exception $e) {
        \Drupal::logger('search_api_ai')->error('Failed to fetch dimensions for engine @engine: @message', [
          '@engine' => $selected_engine,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    // Debugging output.
    dpm([
      'Selected Engine' => $selected_engine,
      'Embedding Engine Configuration' => $engine_config,
    ]);

    // Set the trait configuration.
    $this->setEngineConfiguration([
      'embeddings_engine' => $selected_engine,
      'embeddings_engine_configuration' => $engine_config,
    ]);

    // Build the form using the trait logic.
    $form = $this->engineConfigurationForm($form, $form_state);

    return parent::buildForm($form, $form_state);
  }

  /**
   * Submit the configuration form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $selected_engine = $form_state->getValue('embeddings_engine');
    $engine_config = $form_state->getValue('embeddings_engine_configuration') ?? [];

    if (empty($engine_config['dimension']) || !is_numeric($engine_config['dimension'])) {
      $plugin_manager = \Drupal::service('plugin.manager.embedding_engine');
      try {
        $engine_instance = $plugin_manager->createInstance($selected_engine);
        $engine_config['dimension'] = $engine_instance->getDimension();
      }
      catch (\Exception $e) {
        \Drupal::messenger()->addError($this->t('Failed to load embedding engine: @message', [
          '@message' => $e->getMessage(),
        ]));
        $engine_config['dimension'] = 768; // Default fallback.
      }
    }

    \Drupal::logger('search_api_ai')->debug('Saving engine config: @config', [
      '@config' => print_r($engine_config, TRUE),
    ]);

    $this->config('search_api_ai.settings')
      ->set('embeddings_engine', $selected_engine)
      ->set('embeddings_engine_configuration', $engine_config)
      ->save();

    parent::submitForm($form, $form_state);
  }
}

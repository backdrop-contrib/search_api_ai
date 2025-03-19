<?php

/**
 * Provides helper functions for Search API AI backends.
 */

/**
 * Sets the engine configuration.
 *
 * @param object $backend
 *   The backend object.
 * @param array $configuration
 *   The configuration array.
 */
function search_api_ai_set_engine_configuration(&$backend, array $configuration) {
  $backend->traitConfiguration = $configuration;
}

/**
 * Provides default engine configuration.
 *
 * @return array
 *   The default configuration.
 */
function search_api_ai_default_engine_configuration() {
  return array(
    'embeddings_engine' => NULL,
    'embeddings_engine_configuration' => array(
      'dimension' => 768,
    ),
  );
}

/**
 * Builds the embedding engine configuration form.
 *
 * @param array $form
 *   The form array.
 * @param array $form_state
 *   The form state array.
 * @param array $configuration
 *   The engine configuration.
 *
 * @return array
 *   The modified form array.
 */
function search_api_ai_engine_configuration_form($form, &$form_state, $configuration) {
  // Load available embedding engines.
  $options = search_api_ai_get_embedding_engines_options();

  $form['embeddings_engine'] = array(
    '#type' => 'select',
    '#title' => t('Embeddings Engine'),
    '#options' => $options,
    '#required' => TRUE,
    '#default_value' => isset($configuration['embeddings_engine']) ? $configuration['embeddings_engine'] : '',
    '#description' => t('The service to use for embeddings. Changing this requires reindexing.'),
    '#ajax' => array(
      'callback' => 'search_api_ai_update_embedding_configuration_form',
      'wrapper' => 'embedding-configuration-wrapper',
      'method' => 'replace',
      'effect' => 'fade',
    ),
  );

  $form['embeddings_engine_configuration'] = array(
    '#type' => 'fieldset',
    '#title' => t('Embeddings Engine Configuration'),
    '#prefix' => '<div id="embedding-configuration-wrapper">',
    '#suffix' => '</div>',
    '#collapsible' => TRUE,
    '#collapsed' => FALSE,
  );

  $dimension_value = isset($configuration['embeddings_engine_configuration']['dimension'])
    ? $configuration['embeddings_engine_configuration']['dimension']
    : 768;

  $form['embeddings_engine_configuration']['dimension'] = array(
    '#type' => 'number',
    '#title' => t('Dimensions'),
    '#description' => t('The number of dimensions for the embeddings.'),
    '#default_value' => $dimension_value,
    '#required' => TRUE,
    '#disabled' => TRUE,
  );

  return $form;
}

/**
 * Loads the embeddings engine with a configuration.
 *
 * @param array $configuration
 *   The engine configuration.
 *
 * @return object|null
 *   The embeddings engine instance or NULL if not found.
 */
function search_api_ai_load_embeddings_engine($configuration) {
  $plugin_id = $configuration['embeddings_engine'];
  $plugin_config = $configuration['embeddings_engine_configuration'];

  $engines = module_invoke_all('embedding_engine_info');

  if (isset($engines[$plugin_id])) {
    $engine_class = $engines[$plugin_id]['class'];
    module_load_include('inc', 'search_api_ai', 'includes/' . $engine_class);

    try {
      return new $engine_class($plugin_config);
    } catch (Exception $e) {
      watchdog('search_api_ai', 'Failed to initialize embedding engine: @message', array(
        '@message' => $e->getMessage(),
      ), WATCHDOG_ERROR);
    }
  }
  return NULL;
}

/**
 * Retrieves all available embedding engines as options.
 *
 * @return array
 *   An array of available embedding engines.
 */
function search_api_ai_get_embedding_engines_options() {
  $options = array();
  $engines = module_invoke_all('embedding_engine_info');

  // Ensure the OpenAI module functions are available.
  module_load_include('module', 'openai');

  foreach ($engines as $id => $definition) {
    module_load_include('inc', 'search_api_ai_openai', 'includes/' . $definition['class']);

    // Prepare required arguments.
    $config = array('dimension' => 1536);  // Default config, adjust as needed
    $client = openai_get_client();         // Now this should work since the module is loaded

    // Instantiate the embedding engine with required arguments.
    $engine_instance = new $definition['class']($config, $client);

    if ($engine_instance->isAvailable()) {
      $options[$id] = $definition['label'];
    }
  }

  if (empty($options)) {
    backdrop_set_message(t('No embedding engines available. Please install and enable an embedding engine module.'), 'warning');
  }

  return $options;
}

/**
 * AJAX callback to update the embedding engine configuration form.
 */
function search_api_ai_update_embedding_configuration_form($form, &$form_state) {
  $selected_engine = $form_state['values']['embeddings_engine'];

  if ($selected_engine) {
    $engines = module_invoke_all('embedding_engine_info');

    if (isset($engines[$selected_engine])) {
      $engine_class = $engines[$selected_engine]['class'];
      module_load_include('inc', 'search_api_ai', 'includes/' . $engine_class);

      try {
        $engine_instance = new $engine_class();
        $dimension_value = $engine_instance->getDimension();

        $form_state['values']['embeddings_engine_configuration']['dimension'] = $dimension_value;
      } catch (Exception $e) {
        backdrop_set_message(t('Failed to load embedding engine configuration: @message', array('@message' => $e->getMessage())), 'error');
      }
    }
  }

  return $form['embeddings_engine_configuration'];
}

<?php

/**
 * Helper functions for Search API AI backends (engines + utilities).
 *
 * NOTE: Your hook_embedding_engine_info() must return entries like:
 * [
 *   'openai' => [
 *     'label'  => t('OpenAI Embeddings'),
 *     'class'  => 'OpenAiEmbeddingsDefault',
 *     'file'   => 'includes/OpenAiEmbeddingsDefault.inc',
 *     'module' => 'search_api_ai', // or the providing module name
 *   ],
 * ]
 * Do NOT list Pinecone/Milvus here — those are vector stores (Search API servers),
 * not embedding engines.
 */

/**
 * Sets the engine configuration onto a backend using the shared trait slot.
 */
function search_api_ai_set_engine_configuration(&$backend, array $configuration) {
  $backend->traitConfiguration = $configuration;
}

/**
 * Default engine configuration.
 * Default to 1536 (e.g. text-embedding-3-small).
 */
function search_api_ai_default_engine_configuration() {
  return [
    'embeddings_engine' => NULL,
    'embeddings_engine_configuration' => [
      'dimension' => 1536,
    ],
  ];
}

/**
 * Build the embedding engine configuration form.
 * Dimension is read-only and driven by the selected engine.
 */
function search_api_ai_engine_configuration_form($form, &$form_state, $configuration) {
  $options = search_api_ai_get_embedding_engines_options();

  $form['embeddings_engine'] = [
    '#type' => 'select',
    '#title' => t('Embeddings Engine'),
    '#options' => $options,
    '#required' => TRUE,
    '#default_value' => $configuration['embeddings_engine'] ?? '',
    '#description' => t('Provider used to generate embeddings. Changing this requires reindexing.'),
    '#ajax' => [
      'callback' => 'search_api_ai_update_embedding_configuration_form',
      'wrapper'  => 'embedding-configuration-wrapper',
      'method'   => 'replace',
      'effect'   => 'fade',
    ],
  ];

  $form['embeddings_engine_configuration'] = [
    '#type' => 'fieldset',
    '#title' => t('Embeddings Engine Configuration'),
    '#prefix' => '<div id="embedding-configuration-wrapper">',
    '#suffix' => '</div>',
    '#collapsible' => TRUE,
    '#collapsed' => FALSE,
  ];

  $dimension_value = isset($configuration['embeddings_engine_configuration']['dimension'])
    ? (int) $configuration['embeddings_engine_configuration']['dimension']
    : 1536;

  $form['embeddings_engine_configuration']['dimension'] = [
    '#type' => 'number',
    '#title' => t('Dimensions'),
    '#description' => t('Fixed by the selected embedding model.'),
    '#default_value' => $dimension_value,
    '#required' => TRUE,
    '#disabled' => TRUE,
  ];

  return $form;
}

/**
 * Load an embeddings engine instance for the given configuration.
 */
function search_api_ai_load_embeddings_engine($configuration) {
  $plugin_id     = $configuration['embeddings_engine'] ?? NULL;
  $plugin_config = $configuration['embeddings_engine_configuration'] ?? [];

  if (!$plugin_id) {
    return NULL;
  }

  $engines = module_invoke_all('embedding_engine_info');
  if (empty($engines[$plugin_id])) {
    return NULL;
  }

  $def = $engines[$plugin_id];
  if (empty($def['class']) || empty($def['file']) || empty($def['module'])) {
    watchdog('search_api_ai', 'Invalid engine definition for @id', ['@id' => $plugin_id], WATCHDOG_ERROR);
    return NULL;
  }

  module_load_include('inc', $def['module'], $def['file']);
  if (!class_exists($def['class'])) {
    watchdog('search_api_ai', 'Engine class @c not found', ['@c' => $def['class']], WATCHDOG_ERROR);
    return NULL;
  }

  try {
    // If your engine needs a client, build it here.
    $client = NULL;

    // Extract provider from model ID if prefixed (e.g., "openai/model-name")
    // This is for OpenAI 2.x multi-provider support
    $provider_id = 'openai'; // default
    $model_without_prefix = $plugin_id;
    if (strpos($plugin_id, '/') !== FALSE) {
      list($provider_id, $model_without_prefix) = explode('/', $plugin_id, 2);
    }

    // OpenAI 2.x: Use provider-based API
    // Resolve OpenAI client using compatibility helper.
    if ($provider_id === 'openai' || function_exists('openai_get_api')) {
      try {
        $stripped = NULL;
        $client = search_api_ai_openai_client_for_model($plugin_id, $stripped);
        // If we resolved a stripped model, prefer that for the engine config later
        if (!empty($stripped)) {
          $model_without_prefix = $stripped;
        }
      }
      catch (Exception $e) {
        watchdog('search_api_ai', 'Failed to resolve OpenAI client for @model: @msg', ['@model' => $plugin_id, '@msg' => $e->getMessage()], WATCHDOG_WARNING);
      }
    }

    // Merge engine definition into config so model_id/model_name are passed to the class
    $full_config = array_merge($plugin_config, [
      'model_id' => $model_without_prefix, // unprefixed model name
      'model_name' => $def['model_name'] ?? $model_without_prefix,
      'model_dimension' => $def['model_dimension'] ?? ($plugin_config['dimension'] ?? 1536),
    ]);

    return new $def['class']($full_config, $client);
  }
  catch (Exception $e) {
    watchdog('search_api_ai', 'Failed to initialize embedding engine: @message', ['@message' => $e->getMessage()], WATCHDOG_ERROR);
    return NULL;
  }
}

/**
 * Build the options list for embedding engines.
 * Avoid creating network clients here; rely on static/cheap checks.
 */
function search_api_ai_get_embedding_engines_options() {
  $options = [];
  $engines = module_invoke_all('embedding_engine_info');

  // Group engines by provider for optgroup support
  $grouped = [];

  foreach ($engines as $id => $def) {
    if (empty($def['class']) || empty($def['file']) || empty($def['module'])) {
      continue;
    }
    module_load_include('inc', $def['module'], $def['file']);
    if (!class_exists($def['class'])) {
      continue;
    }

    // If the engine exposes a static availability check, use it.
    $available = TRUE;
    if (method_exists($def['class'], 'isAvailableStatic')) {
      try {
        $available = call_user_func([$def['class'], 'isAvailableStatic']);
      } catch (Exception $e) {
        $available = FALSE;
      }
    }

    if ($available) {
      $label = $def['label'] ?? $id;

      // Try to determine provider from model ID (e.g., "ollama/model-name")
      $provider_label = 'Other';
      if (strpos($id, '/') !== FALSE) {
        // OpenAI 2.x: provider/model format
        $parts = explode('/', $id, 2);
        $provider_id = $parts[0];

        if (function_exists('openai_get_provider_label')) {
          $provider_label = openai_get_provider_label($provider_id);
        }
        else {
          $provider_label = ucfirst($provider_id);
        }
      }
      // Detect OpenAI models by common patterns (for OpenAI 1.x without prefix)
      elseif (preg_match('/^(text-embedding|gpt|davinci|curie|babbage|ada)/i', $id)) {
        $provider_label = 'OpenAI';
      }
      // Check if label contains provider info in parentheses: "model (Provider)"
      elseif (preg_match('/\(([^)]+)\)\s*$/', $label, $matches)) {
        $provider_label = $matches[1];
      }

      if (!isset($grouped[$provider_label])) {
        $grouped[$provider_label] = [];
      }
      $grouped[$provider_label][$id] = $label;
    }
  }

  // Sort providers alphabetically
  ksort($grouped);

  // Return grouped array (Backdrop select supports optgroups)
  $options = $grouped;

  if (empty($options)) {
    backdrop_set_message(t('No embedding engines available. Please enable an embedding engine module.'), 'warning');
  }
  return $options;
}

/**
 * AJAX callback to update the engine configuration (dimension).
 *
 * Compatible with both OpenAI 1.x and 2.x:
 * - OpenAI 2.x: Uses provider/model format (e.g., "openai/text-embedding-3-small")
 * - OpenAI 1.x: Uses model ID only (e.g., "text-embedding-ada-002")
 * Both include 'model_id' in their engine definition, so openai_get_embedding_dimension() works for both.
 */
function search_api_ai_update_embedding_configuration_form($form, &$form_state) {
  $selected = $form_state['values']['embeddings_engine'] ?? NULL;
  if (!$selected) {
    return $form['embeddings_engine_configuration'];
  }

  $engines = module_invoke_all('embedding_engine_info');
  if (empty($engines[$selected])) {
    return $form['embeddings_engine_configuration'];
  }

  $def = $engines[$selected];
  if (empty($def['class']) || empty($def['file']) || empty($def['module'])) {
    return $form['embeddings_engine_configuration'];
  }

  module_load_include('inc', $def['module'], $def['file']);
  if (!class_exists($def['class'])) {
    return $form['embeddings_engine_configuration'];
  }

  $dimension_value = 1536; // sane fallback
  try {
    // For per-model engines (both OpenAI 1.x and 2.x), use openai_get_embedding_dimension
    // This function tries dynamic detection first, then falls back to known dimensions
    if (!empty($def['model_id']) && function_exists('openai_get_embedding_dimension')) {
      // Extract provider from selected engine ID (e.g., "openai/text-embedding-3-small")
      // For OpenAI 1.x, there's no slash, so default to 'openai'
      $provider_id = 'openai'; // default
      if (strpos($selected, '/') !== FALSE) {
        list($provider_id, ) = explode('/', $selected, 2);
      }
      $dimension_value = openai_get_embedding_dimension($def['model_id'], $provider_id);
    }
    // Fallback: try instantiating the engine class
    // This handles any legacy engines or engines without model_id
    else {
      module_load_include('inc', $def['module'], $def['file']);
      if (class_exists($def['class'])) {
        // Build config with model metadata from the engine definition
        $config = [];
        if (!empty($def['model_dimension'])) {
          $config['dimension'] = $def['model_dimension'];
        }
        if (!empty($def['model_id'])) {
          $config['model_id'] = $def['model_id'];
        }
        if (!empty($def['model_name'])) {
          $config['model_name'] = $def['model_name'];
        }

        $engine = new $def['class']($config, NULL);
        if (method_exists($engine, 'getDimension')) {
          $dim = $engine->getDimension();
          if ($dim > 0) {
            $dimension_value = (int) $dim;
          }
        }
      }
    }
  } catch (Exception $e) {
    watchdog('search_api_ai', 'Failed to detect dimension for @id: @m', ['@id' => $selected, '@m' => $e->getMessage()], WATCHDOG_WARNING);
  }

  // Reflect into form/form_state.
  $form_state['values']['embeddings_engine_configuration']['dimension'] = $dimension_value;
  $form['embeddings_engine_configuration']['dimension']['#value'] = $dimension_value;

  return $form['embeddings_engine_configuration'];
}

/* --------------------------------------------------------------------------
 * VECTOR NORMALIZER (works for Milvus & Pinecone)
 * -------------------------------------------------------------------------- */

/**
 * Normalize global $search_api_ai_vectors into a uniform set of rows
 * so both Milvus and Pinecone can ingest either shape:
 *
 * A) FLAT rows (current alter hook):
 *    [
 *      ['entity_type'=>'node','entity_id'=>123,'field_name'=>'search_api_viewed',
 *       'chunk_index'=>0,'vector'=>[...], 'content'=>'...', 'title'=>'...',
 *       'search_api_url'=>'...', 'url'=>'...'],
 *      ...
 *    ]
 *
 * B) LEGACY nested:
 *    $search_api_ai_vectors["node/123"]['vectors'][$field_name] = [
 *      'vectors' => [...], 'content' => '...', 'llm_vector_embedding' => TRUE
 *    ]
 *
 * @param string $entity_type
 * @param int    $entity_id
 * @param int    $expected_dimension
 *
 * @return array of rows:
 *   [
 *     [
 *       'entity_type' => 'node',
 *       'entity_id'   => 123,
 *       'field_name'  => 'search_api_viewed',
 *       'chunk_index' => 0,
 *       'values'      => [floats...],
 *       'metadata'    => ['content'=>'...', 'title'=>'...', 'search_api_url'=>'...', 'url'=>'...'],
 *       'llm_vector_embedding' => TRUE,
 *     ],
 *     ...
 *   ]
 */
function search_api_ai_normalize_vectors($entity_type, $entity_id, $expected_dimension) {
  global $search_api_ai_vectors;
  $out = [];
  if (empty($entity_type) || empty($entity_id)) {
    return $out;
  }

  // -------- FLAT format --------
  if (is_array($search_api_ai_vectors) && isset($search_api_ai_vectors[0]) && is_array($search_api_ai_vectors[0])) {
    foreach ($search_api_ai_vectors as $row) {
      if (!is_array($row)) continue;
      if (($row['entity_type'] ?? NULL) !== $entity_type) continue;
      if ((int)($row['entity_id'] ?? 0) !== (int)$entity_id) continue;

      $vec = $row['vector'] ?? NULL;
      if (!is_array($vec) || count($vec) !== (int)$expected_dimension) {
        continue;
      }

      $out[] = [
        'entity_type' => $entity_type,
        'entity_id'   => (int)$entity_id,
        'field_name'  => $row['field_name'] ?? 'unknown',
        'chunk_index' => isset($row['chunk_index']) ? (int)$row['chunk_index'] : 0,
        'values'      => array_values(array_map('floatval', $vec)),
        'metadata'    => [
          'content'        => isset($row['content']) ? (string)$row['content'] : '',
          'title'          => $row['title'] ?? NULL,
          'search_api_url' => $row['search_api_url'] ?? NULL,
          'url'            => $row['url'] ?? NULL,
        ],
        'llm_vector_embedding' => $row['llm_vector_embedding'] ?? TRUE,
      ];
    }
  }

  // -------- LEGACY nested format --------
  $key = "$entity_type/$entity_id";
  if (isset($search_api_ai_vectors[$key]['vectors']) && is_array($search_api_ai_vectors[$key]['vectors'])) {
    foreach ($search_api_ai_vectors[$key]['vectors'] as $field_name => $entry) {
      if (!is_array($entry) || !isset($entry['vectors'])) continue;
      $vec = $entry['vectors'];
      if (!is_array($vec) || count($vec) !== (int)$expected_dimension) continue;

      $out[] = [
        'entity_type' => $entity_type,
        'entity_id'   => (int)$entity_id,
        'field_name'  => $field_name,
        'chunk_index' => 0,
        'values'      => array_values(array_map('floatval', $vec)),
        'metadata'    => [
          'content' => isset($entry['content']) ? (string)$entry['content'] : '',
        ],
        'llm_vector_embedding' => $entry['llm_vector_embedding'] ?? TRUE,
      ];
    }
  }

  return $out;
}

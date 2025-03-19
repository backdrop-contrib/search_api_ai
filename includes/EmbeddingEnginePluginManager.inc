<?php
/**
 * @file
 * EmbeddingEnginePluginManager for Backdrop CMS.
 */

class EmbeddingEnginePluginManager {

  protected $plugin_definitions = [];
  protected $cache_backend;

  /**
   * Constructs the EmbeddingEnginePluginManager object.
   */
  public function __construct() {
    // Load plugin definitions.
    $this->loadPluginDefinitions();

    // Initialize cache.
    $this->cache_backend = cache_get('embedding_engine_plugins');
    if ($this->cache_backend) {
      $this->plugin_definitions = $this->cache_backend->data;
    } else {
      $this->discoverPlugins();
      cache_set('embedding_engine_plugins', $this->plugin_definitions);
    }
  }

  /**
   * Loads the plugin definitions.
   */
  protected function loadPluginDefinitions() {
    // Load plugin info from hook implementations.
    module_load_include('inc', 'search_api_ai', 'includes/EmbeddingEngineInterface');
    $this->plugin_definitions = module_invoke_all('embedding_engine_info');
    drupal_alter('embedding_engine_info', $this->plugin_definitions);
  }

  /**
   * Discovers plugins in the Plugin/EmbeddingEngine directory.
   */
  protected function discoverPlugins() {
    $modules = module_list();
    foreach ($modules as $module) {
      $path = backdrop_get_path('module', $module) . '/plugins/EmbeddingEngine';
      if (is_dir($path)) {
        foreach (scandir($path) as $file) {
          if (substr($file, -4) === '.inc') {
            module_load_include('inc', $module, 'plugins/EmbeddingEngine/' . substr($file, 0, -4));
            $plugin_class = substr($file, 0, -4);
            if (class_exists($plugin_class)) {
              $this->plugin_definitions[$plugin_class] = new $plugin_class();
            }
          }
        }
      }
    }
  }

  /**
   * Returns plugin definitions.
   */
  public function getDefinitions() {
    watchdog('embedding_engine_plugin_manager', 'Discovered plugin definitions: @definitions', [
      '@definitions' => print_r($this->plugin_definitions, TRUE),
    ]);
    return $this->plugin_definitions;
  }

  /**
   * Creates an instance of the plugin.
   *
   * @param string $plugin_id
   *   The ID of the plugin to instantiate.
   * @param array $configuration
   *   Configuration settings for the plugin.
   *
   * @return object
   *   The plugin instance.
   *
   * @throws Exception
   *   Throws exception if plugin instantiation fails.
   */
  public function createInstance($plugin_id, array $configuration = []) {
    watchdog('embedding_engine', 'Creating plugin instance for ID: @plugin_id with configuration: @configuration', [
      '@plugin_id' => $plugin_id,
      '@configuration' => print_r($configuration, TRUE),
    ]);

    if (isset($this->plugin_definitions[$plugin_id])) {
      $instance = clone $this->plugin_definitions[$plugin_id];
      foreach ($configuration as $key => $value) {
        $instance->$key = $value;
      }

      watchdog('embedding_engine', 'Successfully created instance of plugin ID: @plugin_id', [
        '@plugin_id' => $plugin_id,
      ]);
      return $instance;
    }
    else {
      watchdog('embedding_engine', 'Error creating instance for plugin ID: @plugin_id. Plugin not found.', [
        '@plugin_id' => $plugin_id,
      ], WATCHDOG_ERROR);
      throw new Exception(t('Plugin ID @plugin_id not found.', ['@plugin_id' => $plugin_id]));
    }
  }
}

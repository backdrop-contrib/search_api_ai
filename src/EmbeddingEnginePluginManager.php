<?php

declare(strict_types=1);

namespace Drupal\search_api_ai;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\search_api_ai\Attribute\EmbeddingEngine;

/**
 * EmbeddingEngine plugin manager.
 */
final class EmbeddingEnginePluginManager extends DefaultPluginManager implements PluginManagerInterface {

  /**
   * Constructs the EmbeddingEnginePluginManager object.
   *
   * @param \Traversable $namespaces
   *   An object that provides the root paths keyed by the corresponding
   *   namespace to look for plugin implementations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   The cache backend instance to use.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler to pass to plugin discovery.
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler
  ) {
    // Add debug log to track the constructor call.
    \Drupal::logger('embedding_engine')->debug('Initializing EmbeddingEnginePluginManager.');

    // Specify the directory to look for plugins.
    parent::__construct('Plugin/EmbeddingEngine', $namespaces, $module_handler, EmbeddingEngineInterface::class, EmbeddingEngine::class);

    // Allow other modules to alter the discovered plugins.
    $this->alterInfo('embedding_engine_info');

    // Use a specific cache bin for these plugins.
    $this->setCacheBackend($cache_backend, 'embedding_engine_plugins');

    // Log successful initialization.
    \Drupal::logger('embedding_engine')->debug('EmbeddingEnginePluginManager initialized successfully.');
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinitions() {
    // Add debug to log the discovery of plugin definitions.
    $definitions = parent::getDefinitions();
    //dpm($definitions);
    \Drupal::logger('embedding_engine_plugin_manager')->debug('Discovered plugin definitions: @definitions', [
      '@definitions' => print_r($definitions, TRUE),
    ]);
    return $definitions;
  }

  /**
   * {@inheritdoc}
   */
  public function createInstance($plugin_id, array $configuration = []) {
    // Debug the creation process of a plugin instance.
    \Drupal::logger('embedding_engine')->debug('Creating plugin instance for ID: @plugin_id with configuration: @configuration', [
      '@plugin_id' => $plugin_id,
      '@configuration' => print_r($configuration, TRUE),
    ]);

    try {
      $instance = parent::createInstance($plugin_id, $configuration);
      \Drupal::logger('embedding_engine')->debug('Successfully created instance of plugin ID: @plugin_id', [
        '@plugin_id' => $plugin_id,
      ]);
      return $instance;
    }
    catch (\Exception $e) {
      \Drupal::logger('embedding_engine')->error('Error creating instance for plugin ID: @plugin_id. Error: @message', [
        '@plugin_id' => $plugin_id,
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }
}

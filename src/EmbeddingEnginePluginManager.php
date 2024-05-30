<?php

declare(strict_types=1);

namespace Drupal\search_api_ai;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\search_api_ai\Attribute\EmbeddingEngine;


/**
 * EmbeddingEngine plugin manager.
 */
final class EmbeddingEnginePluginManager extends DefaultPluginManager {

  /**
   * Constructs the object.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/EmbeddingEngine', $namespaces, $module_handler, EmbeddingEngineInterface::class, EmbeddingEngine::class);
    $this->alterInfo('embedding_engine_info');
    $this->setCacheBackend($cache_backend, 'embedding_engine_plugins');
  }

}

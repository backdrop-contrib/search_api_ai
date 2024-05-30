<?php

namespace Drupal\search_api_ai\EventListener;

use Drupal\search_api\Event\IndexingItemsEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Drupal\search_api_ai\EmbeddingEngineStatic;
use Drupal\search_api_ai\SearchApiAiBackendInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Provides an event listener for setting the embed engine on embeddings.
 *
 * @see \Drupal\Tests\search_api\Functional\EventsTest
 */
class SearchApiEventListener implements EventSubscriberInterface {

  /**
   * The embeddings engine.
   *
   * @var \Drupal\search_api_ai\EmbeddingEngineStatic
   */
  protected $embedEngine;

  /**
   * Constructs a new class instance.
   *
   * @param \Drupal\search_api_ai\EmbeddingEngineStatic $embed_engine_static
   *   The embeddings engine.
   */
  public function __construct(EmbeddingEngineStatic $embed_engine_static) {
    $this->embedEngine = $embed_engine_static;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      SearchApiEvents::INDEXING_ITEMS => 'indexingItems',
    ];
  }

  /**
   * Reacts to the indexing items event.
   *
   * @param \Drupal\search_api\Event\IndexingItemsEvent $event
   *   The indexing items event.
   */
  public function indexingItems(IndexingItemsEvent $event) {
    $index = $event->getIndex();
    $server = $index->getServerInstance()->getBackend();
    // Check so the server is the right class.
    if (!($server instanceof SearchApiAiBackendInterface)) {
      return;
    }
    /** @var \Drupal\search_api_ai\Backend\SearchApiAiBackendPluginBase $server */
    $server->setEngineConfiguration($server->getConfiguration());
    $embeddings_engine = $server->loadEmbeddingsEngine();
    if ($embeddings_engine) {
      // Because we have no context during indexing, we need to store the engine
      // in a static class, so that the Embeddings data type can react to it.
      $this->embedEngine->setEmbeddingEngine($embeddings_engine);
    }
  }

}

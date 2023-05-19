<?php

/**
 * @file
 * Views hook implementations for Search API Pinecone.
 */

use Drupal\search_api\Entity\Index;

/**
 * Implements hook_views_data_alter().
 */
function search_api_pinecone_views_data_alter(array &$data) {
  // Remove any pinecone indexes from Search API.
  foreach (Index::loadMultiple() as $index) {
    if ($index->getServerInstance()?->getBackendId() === 'search_api_pinecone') {
      unset($data['search_api_index_' . $index->id()]);
    }
  }
}

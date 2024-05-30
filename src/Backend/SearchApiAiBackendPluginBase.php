<?php

namespace Drupal\search_api_ai\Backend;

use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\Backend\BackendPluginBase;
use Drupal\search_api_ai\Trait\SearchApiAiBackendTrait;

/**
 * Base class for Search API AI backend plugins.
 *
 * This will add some of the function that are not needed for the interface
 * with empty implementations. This adds logic for loading and storing
 * embedding engines. This uses the SearchApiAiBackendTrait for all logic
 * automatically. Database engines that has to extend other classes should
 * use the trait directly.
 */
abstract class SearchApiAiBackendPluginBase extends BackendPluginBase {

  use SearchApiAiBackendTrait;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return $this->defaultEngineConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $this->setEngineConfiguration($this->configuration);
    return $this->engineConfigurationForm($form, $form_state);
  }

}

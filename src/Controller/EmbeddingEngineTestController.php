<?php

namespace Drupal\search_api_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormState;
use Drupal\search_api_ai\Trait\SearchApiAiBackendTrait;

/**
 * Controller to test embedding engine form rendering.
 */
class EmbeddingEngineTestController extends ControllerBase {
  use SearchApiAiBackendTrait;

  /**
   * Render the embedding engine test form.
   *
   * @return array
   *   The renderable form array.
   */
  public function testForm() {
    // Create an empty form state.
    $form_state = new FormState();

    // Mock up a configuration for testing.
    $this->setEngineConfiguration([
      'embeddings_engine' => NULL,
      'embeddings_engine_configuration' => [
        'dimensions' => 768,
      ],
    ]);

    // Add the form elements using the trait's method.
    $form = [];
    $form += $this->engineConfigurationForm($form, $form_state);

    return $form;
  }

}



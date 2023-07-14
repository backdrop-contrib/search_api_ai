<?php

namespace Drupal\search_api_ai\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\openai\Utility\StringHelper;
use OpenAI\Client;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Search API AI form.
 */
class ChatForm extends FormBase {

  use DependencySerializationTrait;

  /**
   * Construct the OpenAI Search form.
   *
   * @param \OpenAI\Client $aiClient
   *   The OpenAI client.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly Client $aiClient,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('openai.client'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'search_api_ai_chat';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['query'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Send a message'),
      '#title_display' => 'invisible',
      '#attributes' => [
        'placeholder' => $this->t('Send a message'),
      ],
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send'),
      '#ajax' => [
        'callback' => '::getResponse',
        'progress' => [
          'type' => 'throbber',
        ],
      ],
    ];

    $form['response'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#weight' => 100,
      '#id' => Html::getId($form_state->getBuildInfo()['block_id'] . '-response'),
      '#value' => nl2br($form_state->get('response')),
    ];

    $form['actions']['submit']['#ajax']['wrapper'] = $form['response']['#id'];

    $form['#attached']['library'][] = 'core/drupal.form';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $form_state->setRebuild();
    $chat_config = $this->getChatConfig($form_state);

    /** @var \Drupal\search_api\IndexInterface $index */
    $index = $this->entityTypeManager
      ->getStorage('search_api_index')
      ->load($chat_config['index']);
    $backend = $index->getServerInstance()->getBackend();
    $namespace = $backend->getNamespace($index);

    $vector_filter_ids = [];
    // If a View is configured, execute it and add the IDs to the filter.
    if (!empty($chat_config['view'])) {
      $view = $this->entityTypeManager->getStorage('view')->load($chat_config['view']);
      $view->getExecutable()->execute();

      foreach ($view->getExecutable()->result as $resultRow) {
        $vector_filter_ids[] = "entity:{$resultRow->_entity->getEntityTypeId()}/{$resultRow->_entity->id()}:{$resultRow->_entity->language()->getId()}";
      }
    }

    // If entity types are configured, check if any of them are present in the route.
    if (!empty($chat_config['entity_types'])) {
      foreach ($chat_config['entity_types'] as $entity_type) {
        if ($entity = \Drupal::routeMatch()->getParameter($entity_type)) {
          $vector_filter_ids[] = "entity:{$entity->getEntityTypeId()}/{$entity->id()}:{$entity->language()->getId()}";
        }
      }
    }

    $messages = [
      [
        'role' => 'system',
        'content' => $chat_config['chat_system_role'],
      ],
    ];
    $user_query = StringHelper::prepareText($form_state->getValue('query'), [], $chat_config['max_length']);

    // Create the embedding for the latest question.
    try {
      $response = $this->aiClient->embeddings()->create([
        'model' => $chat_config['model'],
        'input' => $user_query,
      ])->toArray();
      $query_embedding = $response['data'][0]['embedding'] ?? NULL;
      if (!$query_embedding) {
        $this->logger('search_api_ai')->error('Error retrieving prompt embedding.');
        $form_state->set('response', $chat_config['error_message']);
        return;
      }
    }
    catch (\Exception $exception) {
      watchdog_exception('search_api_ai', $exception);
      $form_state->set('response', $chat_config['error_message']);
      return;
    }

    $filters = [];
    if ($vector_filter_ids) {
      $filters['item_id'] = ['$in' => $vector_filter_ids];
    }
    // Find the best matches from the vector store.
    try {
      /** @var \Drupal\search_api\IndexInterface $index */
      $index = $this->entityTypeManager
        ->getStorage('search_api_index')
        ->load($chat_config['index']);

      $query = $index->query([
        'query_embedding' => $query_embedding,
        'top_k' => $chat_config['top_k'],
        'include_metadata' => TRUE,
        'include_values' => FALSE,
        'filters' => $filters,
        'namespace' => $namespace,
      ]);
      $results = $query->execute();
    }
    catch (\Exception $exception) {
      watchdog_exception('search_api_ai', $exception);
      $form_state->set('response', $chat_config['error_message']);
      return;
    }

    // Set empty response message and return.
    if ($results->getResultCount() === 0) {
      $form_state->set('response', $chat_config['no_results_message']);
      return;
    }

    // Create a system chat message for each result that meets the threshold.
    foreach ($results as $match) {
      if ($match->getScore() < $chat_config['score_threshold']) {
        continue;
      }

      $entity = $index->loadItem($match->getExtraData('metadata')->item_id)->getValue();
      $content = StringHelper::prepareText(trim($match->getExtraData('metadata')->content), [], 1024);
      $messages[] = [
        'role' => 'system',
        'content' => "Source link: {$entity->toLink()->toString()}\nSnippet: {$content}",
      ];
    }

    // Send the query to OpenAI.
    $messages[] = [
      'role' => 'user',
      'content' => $user_query,
    ];
    try {
      $result = $this->aiClient->chat()->create([
        'model' => $chat_config['chat_model'],
        'messages' => $messages,
        'temperature' => (float) $chat_config['temperature'],
        'max_tokens' => (int) $chat_config['max_tokens'],
      ])->toArray();
      $form_state->set('response', trim($result["choices"][0]["message"]['content']) ?? $chat_config['no_response_message']);
    }
    catch (\Exception $exception) {
      $this->messenger()->addError("OpenAI exception: {$exception->getMessage()}");
      return;
    }
  }

  /**
   * AJAX callback to populate the response.
   */
  public function getResponse(array &$form, FormStateInterface $form_state) {
    return $form['response'];
  }

  /**
   * Get all the Chat config from build info with defaults.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state to get build info from.
   *
   * @return array
   *   The array of chat config with defaults where required.
   */
  protected function getChatConfig(FormStateInterface $form_state) {
    $config = $form_state->getBuildInfo()['chat_config'] ?? [];
    return $config + [
      'top_k' => 8,
      'score_threshold' => 0.5,
      'max_length' => 1024,
      'model' => 'text-embedding-ada-002',
      'no_results_message' => "Sorry, I couldn't find what you are looking for.",
      'error_message' => 'Sorry, something went wrong. Please try again later.',
      'no_response_message' => 'No answer was provided.',
      'debug' => FALSE,
      'chat_model' => 'gpt-4',
      'temperature' => 0.4,
      'max_tokens' => 1024,
      'chat_system_role' => 'You are a chat bot to help find resources and provide links and references.',
    ];
  }

}

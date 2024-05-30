<?php

namespace Drupal\search_api_ai_simple_chatbot\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Utility\Error;
use Drupal\openai\Utility\StringHelper;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\ResultSetInterface;
use OpenAI;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Provides a Search API AI form.
 */
class ChatForm extends FormBase {

  use DependencySerializationTrait;

  /**
   * Construct the OpenAI Search form.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
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
    $form['#attached']['library'][] = 'search_api_ai_simple_chatbot/chat';

    $response_id = Html::getId($form_state->getBuildInfo()['block_id'] . '-response');
    $form['response'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'id' => $response_id,
        'class' => ['chat-form-response'],
      ],
      '#value' => nl2br($form_state->get('response') ?? ''),
    ];

    $form['query'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Ask me a question'),
      '#title_display' => 'invisible',
      '#attributes' => [
        'placeholder' => $this->t('Ask me a question'),
        'class' => ['chat-form-query'],
      ],
      '#required' => TRUE,
      '#rows' => 1,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send'),
      '#attributes' => [
        'data-search-api-ai-ajax' => $response_id,
        'class' => ['chat-form-send'],
      ],
      '#attached' => [
        'library' => ['search_api_ai_simple_chatbot/form-stream'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $chat_config = $this->getChatConfig($form_state);

    /** @var \Drupal\search_api\IndexInterface $index */
    $index = $this->entityTypeManager
      ->getStorage('search_api_index')
      ->load($chat_config['index']);

    $user_query = StringHelper::prepareText($form_state->getValue('query'), [], $chat_config['max_length']);
    $query_vectors = $this->getQueryVectors($index, $user_query, $chat_config, $form_state);

    // If there are no results, set empty response message and return.
    if (empty($query_vectors) || $query_vectors->getResultCount() === 0) {
      $form_state->set('response', $chat_config['no_results_message']);
      return;
    }

    // Set up the messages to send to the AI system with a base system message.
    $messages = [
      [
        'role' => 'system',
        'content' => $chat_config['chat_system_role'],
      ],
    ];

    // Create a system chat message for each result that meets the threshold.
    $context = '';
    foreach ($query_vectors as $match) {
      if ($match->getScore() < $chat_config['score_threshold']) {
        continue;
      }

      // If the entity can be loaded, prepare a trimmed version as context.
      if ($entity = $index->loadItem($match->getExtraData('drupal_entity_id'))->getValue()) {
        $content = StringHelper::prepareText(trim($match->getExtraData('content')), [], 1024);
        $context .= "Source link: {$entity->toLink()->toString()}\nSnippet: {$content}\n\n";
      }
    }

    // If we have any context, add a message containing it.
    if ($context) {
      $messages[] = [
        'role' => 'assistant',
        'content' => str_replace('[context]', $context, $chat_config['assistant_message']),
      ];
    }

    // Add a message with the user query, wrapped in the template.
    $messages[] = [
      'role' => 'user',
      'content' => str_replace('[user-prompt]', $user_query, $chat_config['user_message']),
    ];

    // Send the query to OpenAI.
    if ($this->getRequest()->isXmlHttpRequest()) {
      try {
        $http_response = new StreamedResponse();
        $http_response->setCallback(function () use ($chat_config, $messages) {
          if (substr($chat_config['chat_model'], 0, 7) == 'openai-') {
            $stream = \Drupal::service('openai.client')->chat()->createStreamed([
              'model' => str_replace('openai-', '', $chat_config['chat_model']),
              'messages' => $messages,
              'temperature' => (float) $chat_config['temperature'],
              'max_tokens' => (int) $chat_config['max_tokens'],
            ]);
          }
          else if (substr($chat_config['chat_model'], 0, 12) == 'fireworksai-') {
            $model = 'accounts/fireworks/models/' . str_replace('fireworksai-', '', $chat_config['chat_model']);
            $client = OpenAI::factory()
             ->withApiKey(\Drupal::config('fireworksai.settings')->get('api_key'))
             ->withBaseUri('https://api.fireworks.ai/inference/v1')
             ->make();
            $stream = $client->chat()->createStreamed([
              'model' => $model,
              'messages' => $messages,
              'stream' => TRUE,
            ]);
          }
          foreach ($stream as $response) {
            echo $response->choices[0]->delta->content;
            ob_flush();
            flush();
          }
        });

        $form_state->setResponse($http_response);
      } catch (\Exception $exception) {
        $this->messenger()
          ->addError("OpenAI exception: {$exception->getMessage()}");
        return;
      }
    }
    else {
      if (substr($chat_config['chat_model'], 0, 7) == 'openai-') {
        $chat_response = $this->aiClient->chat()->create([
          'model' => str_replace('openai-', '', $chat_config['chat_model']),
          'messages' => $messages,
          'temperature' => (float) $chat_config['temperature'],
          'max_tokens' => (int) $chat_config['max_tokens'],
        ]);
      }
      else if (substr($chat_config['chat_model'], 0, 12) == 'fireworksai-') {
        $model = 'accounts/fireworks/models/' . str_replace('fireworksai-', '', $chat_config['chat_model']);
        $chat_response = json_decode(\Drupal::service('fireworksai.api')->chatCompletion($messages, $model, [
          'temperature' => (float) $chat_config['temperature'],
          'max_tokens' => (int) $chat_config['max_tokens'],
        ]));
      }
      $form_state->setRebuild();
      $form_state->set('response', $chat_response->choices[0]->message->content);
    }
  }

  /**
   * Get the query vectors from a search Index.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The index to get the vectors from.
   * @param string $user_query
   *   The user query.
   * @param array $chat_config
   *   The chat configuration options.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   *
   * @return \Drupal\search_api\Query\ResultSetInterface|NULL
   *   The result of the query or NULL if an error occurs.
   *   Errors will be added to the form state with a 'response' key.
   */
  protected function getQueryVectors(IndexInterface $index, string $user_query, array $chat_config, FormStateInterface $form_state): ResultSetInterface|NULL {
    $server = $index->getServerInstance();
    $backend = $server->getBackend();
    /** @var \Drupal\search_api_ai\Backend\SearchApiAiBackendPluginBase $backend */
    $backend->setEngineConfiguration($backend->getConfiguration());
    $embedding_engine = $backend->loadEmbeddingsEngine();

    $vector_filter_ids = [];

    // If entity types are configured, check if any of them are present in the route.
    if (!empty($chat_config['entity_types'])) {
      foreach ($chat_config['entity_types'] as $entity_type) {
        if ($entity = \Drupal::routeMatch()->getParameter($entity_type)) {
          $vector_filter_ids[] = "entity:{$entity->getEntityTypeId()}/{$entity->id()}:{$entity->language()->getId()}";
        }
      }
    }

    // If a View is configured, execute it and add the IDs to the filter.
    // Only check views config if we have no route matched ids.
    if (empty($vector_filter_ids) && !empty($chat_config['view'])) {
      $view = $this->entityTypeManager->getStorage('view')->load($chat_config['view']);
      /** @var \Drupal\views\Entity\View $view */
      $view->getExecutable()->execute();

      foreach ($view->getExecutable()->result as $resultRow) {
        $vector_filter_ids[] = "entity:{$resultRow->_entity->getEntityTypeId()}/{$resultRow->_entity->id()}:{$resultRow->_entity->language()->getId()}";
      }
    }

    // Create the embedding for the latest question.
    try {
      $query_embedding = $embedding_engine->generateEmbeddings($user_query);
      if (!$query_embedding) {
        $this->logger('search_api_ai_simple_chatbot')->error('Error retrieving prompt embedding.');
        $form_state->set('response', $chat_config['error_message']);
        return NULL;
      }
    }
    catch (\Exception $exception) {
      $logger = $this->logger('search_api_ai_simple_chatbot');
      Error::logException($logger, $exception);
      $form_state->set('response', $chat_config['error_message']);
      return NULL;
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
        'limit' => 20,
      ]);
      $results = $query->execute();
    }
    catch (\Exception $exception) {
      $logger = $this->logger('search_api_ai_simple_chatbot');
      Error::logException($logger, $exception);
      $form_state->set('response', $chat_config['error_message']);
      return NULL;
    }

    return $results;
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
        'index' => NULL,
        'view' => NULL,
        'entity_types' => [],
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
        'chat_system_role' => "You are a chat bot to help find resources and provide links and references from the User's private knowledgebase. You will base all your answers off the provided context that you find from the user's knowledgebase. Always return links as HTML.",
        'assistant_message' => <<<EOF
I found the following information in the User's Knowledge Base:
[context]

I will respond with information from the User's Knowledge Base above.
EOF,
        'user_message' => <<<EOF
[user-prompt]

Provide your answer only from the provided context. Do not remind me what I asked you for. Do not apologize. Do not self-reference.
EOF,
    ];
  }

}

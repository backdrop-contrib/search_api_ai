<?php

namespace Drupal\search_api_ai_explorer\Form;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\openai\Utility\StringHelper;
use Drupal\openai_embeddings\Http\PineconeClient;
use Drupal\search_api_pinecone\Plugin\search_api\backend\SearchApiPineconeBackend;
use OpenAI\Client;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Search API Pinecone form.
 */
class SearchForm extends FormBase {

  use DependencySerializationTrait;

  const EMBEDDING_MODEL = 'text-embedding-ada-002';

  const PINECONE_THRESHOLD = 0.5;

  const PINECONE_TOPK = 4;

  const CHAT_MODEL = 'gpt-4';

  const CHAT_TEMPERATURE = 0.4;

  const CHAT_MAX_TOKENS = 1024;

  /**
   * Construct the OpenAI Search form.
   *
   * @param \OpenAI\Client $aiClient
   *   The OpenAI client.
   * @param \Drupal\openai_embeddings\Http\PineconeClient $pineconeClient
   *   The Pinecone client.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly Client $aiClient,
    private readonly PineconeClient $pineconeClient,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('openai.client'),
      $container->get('openai_embeddings.pinecone_client'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'search_api_pinecone_search';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['index'] = [
      '#type' => 'select',
      '#title' => $this->t('Index'),
      '#description' => $this->t('Select the Pinecone index for the embeddings store.'),
      '#options' => [],
    ];
    $indexes = $this->entityTypeManager
      ->getStorage('search_api_index')
      ->loadMultiple();
    foreach ($indexes as $index) {
      if ($index->getServerInstance()?->getBackendId() === 'search_api_pinecone') {
        $form['index']['#options'][$index->id()] = $index->label();
      }
    }

    $messages = $form_state->get('messages') ?? [];
    $form['messages'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
    ];
    $showSystem = !empty($form_state->getValue('system_messages'));
    foreach ($messages as $message) {
      if (!$showSystem && $message['role'] === 'system') {
        continue;
      }

      $form['messages'][] = [
        'role' => [
          '#type' => 'html_tag',
          '#tag' => 'h6',
          '#value' => $message['role'],
        ],
        'content' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#value' => nl2br($message['content']),
        ],
      ];
    }

    $form['query'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Ask ChatGPT a question'),
      '#required' => TRUE,
    ];

    $form['options'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced options'),
    ];
    $form['options']['system_messages'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show system messages'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send'),
    ];

    $form['#attached']['library'][] = 'core/drupal.form';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $form_state->setRebuild();

    /** @var \Drupal\search_api\IndexInterface $index */
    $index = $this->entityTypeManager
      ->getStorage('search_api_index')
      ->load($form_state->getValue('index'));
    $backend = $index->getServerInstance()->getBackend();
    assert($backend instanceof SearchApiPineconeBackend);
    $namespace = $backend->getNamespace($index);
    $score_threshold = self::PINECONE_THRESHOLD;

    // @todo Retain history.
    $messages = [
      [
        'role' => 'system',
        'content' => 'You are a chat bot to help find documents and provide links and references.',
      ],
    ];
    $query = StringHelper::prepareText($form_state->getValue('query'), [], 1024);

    // Create the embedding for the latest question.
    try {
      $response = $this->aiClient->embeddings()->create([
        'model' => self::EMBEDDING_MODEL,
        'input' => $query,
      ])->toArray();
      $query_embedding = $response['data'][0]['embedding'] ?? NULL;
      if (!$query_embedding) {
        $this->messenger()->addError($this->t('Unexpected embedding response.'));
        return;
      }
    }
    catch (\Exception $exception) {
      $this->messenger()->addError("Query embedding exception: {$exception->getMessage()}");
      return;
    }

    // Find the best matches from pinecone.
    try {
      $response = $this->pineconeClient->query(
        $query_embedding,
        self::PINECONE_TOPK,
        TRUE,
        FALSE,
        [],
        $namespace,
      );
      $result = json_decode($response->getBody()->getContents(), flags: \JSON_THROW_ON_ERROR);
      if (empty($result->matches)) {
        $this->messenger()->addError("Zero pinecone results");
        return;
      }
      foreach ($result->matches as $match) {
        if ($match->score < $score_threshold) {
          continue;
        }

        $entity = $index->loadItem($match->metadata->item_id)->getValue();
        $content = StringHelper::prepareText(trim($match->metadata->content), [], 1024);
        $messages[] = [
          'role' => 'system',
          'content' => "Source: {$entity->toLink()->toString()}\nSnippet: {$content}",
        ];
      }
    }
    catch (\Exception $exception) {
      $this->messenger()->addError("Pinecone query exception: {$exception->getMessage()}");
      return;
    }

    // Send the query to OpenAI.
    $messages[] = [
      'role' => 'user',
      'content' => $query,
    ];
    try {
      $result = $this->aiClient->chat()->create([
        'model' => self::CHAT_MODEL,
        'messages' => $messages,
        'temperature' => self::CHAT_TEMPERATURE,
        'max_tokens' => self::CHAT_MAX_TOKENS,
      ])->toArray();
      $messages[] = [
        'role' => 'assistant',
        'content' => trim($result["choices"][0]["message"]['content']) ?? $this->t('No answer was provided.'),
      ];
    }
    catch (\Exception $exception) {
      $this->messenger()->addError("OpenAI exception: {$exception->getMessage()}");
      return;
    }

    $form_state->set('messages', $messages);
  }

}

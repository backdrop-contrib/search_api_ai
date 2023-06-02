<?php

namespace Drupal\search_api_ai\Form;

use Drupal\Component\Utility\Html;
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
 * Provides a Search API AI form.
 */
class ChatForm extends FormBase {

  use DependencySerializationTrait;

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
    // @todo Replace this with the index so we can be backend agnostic.
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

    /** @var \Drupal\search_api\IndexInterface $index */
    $index = $this->entityTypeManager
      ->getStorage('search_api_index')
      ->load($form_state->getBuildInfo()['index']);
    $backend = $index->getServerInstance()->getBackend();
    assert($backend instanceof SearchApiPineconeBackend);
    $namespace = $backend->getNamespace($index);
    // @todo Make configurable.
    $score_threshold = '0.5';

    $messages = [
      [
        'role' => 'user',
        'content' => 'You are a chat bot to help find content on the site. You always link to your sources.',
      ],
    ];
    // @todo Make length configurable.
    $query = StringHelper::prepareText($form_state->getValue('query'), [], 1024);

    // Create the embedding for the latest question.
    try {
      $response = $this->aiClient->embeddings()->create([
        // @todo Make configurable.
        'model' => 'text-embedding-ada-002',
        'input' => $query,
      ])->toArray();
      $query_embedding = $response['data'][0]['embedding'] ?? NULL;
      if (!$query_embedding) {
        $this->logger('search_api_ai')->error('Error retrieving prompt embedding.');
        // @todo Make configurable.
        $form_state->set('response', $this->t('Sorry, something went wrong. Please try again later.'));
        return;
      }
    }
    catch (\Exception $exception) {
      watchdog_exception('search_api_ai', $exception);
      // @todo Make configurable.
      $form_state->set('response', $this->t('Sorry, something went wrong. Please try again later.'));
      return;
    }

    // Find the best matches from pinecone.
    try {
      $response = $this->pineconeClient->query(
        $query_embedding,
        // @todo Make configurable.
        8,
        TRUE,
        FALSE,
        [],
        $namespace,
      );
      $result = json_decode($response->getBody()->getContents(), flags: \JSON_THROW_ON_ERROR);
      if (empty($result->matches)) {
        // @todo Make configurable.
        $form_state->set('response', $this->t("Sorry, I couldn't find what you are looking for."));
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
          'content' => "Source link: {$entity->toLink()->toString()}\nSnippet: {$content}",
        ];
      }
    }
    catch (\Exception $exception) {
      watchdog_exception('serach_api_ai', $exception);
      // @todo Make configurable.
      $form_state->set('response', $this->t('Sorry, something went wrong. Please try again later.'));
      return;
    }

    // Send the query to OpenAI.
    $messages[] = [
      'role' => 'user',
      'content' => $query,
    ];
    try {
      $result = $this->aiClient->chat()->create([
        // @todo Make configurable.
        'model' => 'gpt-4',
        'messages' => $messages,
        // @todo Make configurable.
        'temperature' => 0.4,
        // @todo Make configurable.
        'max_tokens' => 1024,
      ])->toArray();
      // @todo Make empty response configurable.
      $form_state->set('response', trim($result["choices"][0]["message"]['content']) ?? $this->t('No answer was provided.'));
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

}

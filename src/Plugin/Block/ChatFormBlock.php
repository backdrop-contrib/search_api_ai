<?php

namespace Drupal\search_api_ai\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\search_api_ai\Form\ChatForm;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a search api ai: chat form block.
 *
 * @Block(
 *   id = "search_api_ai_chat_form",
 *   admin_label = @Translation("Search API AI: Chat form"),
 *   category = @Translation("AI")
 * )
 */
class ChatFormBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected readonly EntityTypeManagerInterface $entityTypeManager;

  /**
   * The form builder.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected readonly FormBuilderInterface $formBuilder;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $plugin = new static($configuration, $plugin_id, $plugin_definition);
    $plugin->entityTypeManager = $container->get('entity_type.manager');
    $plugin->formBuilder = $container->get('form_builder');
    return $plugin;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
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
      'chat_system_role' => 'You are a chat bot to help find resources and provide links and references.',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form['index'] = [
      '#type' => 'select',
      '#title' => $this->t('Index'),
      '#description' => $this->t('Select the index for the embeddings store.'),
      '#options' => [],
    ];

    $indexes = $this->entityTypeManager
      ->getStorage('search_api_index')
      ->loadMultiple();
    foreach ($indexes as $index) {
      if ($index->getServerInstance()?->getBackendId() === 'search_api_pinecone') {
        $form['index']['#options'][$index->id()] = $index->label();
        if ($index->id() === $this->configuration['index']) {
          $form['index']['#default_value'] = $this->configuration['index'];
        }
      }

    }

    $form['view'] = [
      '#type' => 'select',
      '#title' => $this->t('View'),
      '#description' => $this->t('Optionally, select the View to restrict results to.'),
      '#default_value' => $this->configuration['view'],
      '#empty_option' => $this->t('- None -'),
      '#empty_value' => '',
      '#sort_options' => TRUE,
    ];

    $views = $this->entityTypeManager
      ->getStorage('view')
      ->loadMultiple();
    foreach ($views as $view) {
      $form['view']['#options'][$view->id()] = $view->label();
    }

    $form['entity_types'] = [
      '#type' => 'select',
      '#title' => $this->t('Entity Types'),
      '#description' => $this->t('Optionally, select entity types. When showing
        the block, the vector query will be filtered to an entity of any of
        those types if they can be found in the route parameters.'),
      '#default_value' => $this->configuration['entity_types'],
      '#multiple' => TRUE,
      '#options' => [],
      '#sort_options' => TRUE,
    ];

    $entity_types = $this->entityTypeManager->getDefinitions();
    foreach ($entity_types as $entity_type_id => $entity_type) {
      if ($entity_type instanceof ContentEntityTypeInterface) {
        $form['entity_types']['#options'][$entity_type_id] = $entity_type->getLabel();
      }
    }
    $form['entity_types']['#size'] = min(10, count($form['entity_types']['#options']));

    $form['no_results_message'] = [
      '#type' => 'textfield',
      '#title' => $this->t('No results message'),
      '#description' => $this->t('The error message to show when no vector matches are found.'),
      '#default_value' => $this->configuration['no_results_message'],
    ];

    $form['error_message'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Error message'),
      '#description' => $this->t('The error message to show when an API error occurs.'),
      '#default_value' => $this->configuration['error_message'],
    ];

    $form['no_response_message'] = [
      '#type' => 'textfield',
      '#title' => $this->t('No response message'),
      '#description' => $this->t('The error message to show when no response is received but not error occurs.'),
      '#default_value' => $this->configuration['no_response_message'],
    ];

    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced'),
      '#group' => 'advanced',
      '#collapsible' => TRUE,
      '#open' => FALSE,
    ];

    $form['advanced']['top_k'] = [
      '#type' => 'number',
      '#title' => $this->t('Top K'),
      '#description' => $this->t('The number of results to return for a vector query.'),
      '#default_value' => $this->configuration['top_k'],
      '#step' => 1,
      '#min' => 1,
    ];

    $form['advanced']['score_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Score threshold'),
      '#group' => 'advanced',
      '#description' => $this->t('The threshold vector embeddings must meet to be considered relevant. Must be between 0 and 1.
         A threshold of 0 would mean everything should be considered relevant and a threshold of 1 would mean they have to be
         the same to be considered relevant.'),
      '#default_value' => $this->configuration['score_threshold'],
      '#step' => 0.01,
      '#min' => 0,
      '#max' => 1,
    ];

    $form['advanced']['max_length'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum query length'),
      '#group' => 'advanced',
      '#description' => $this->t('The maximum length of the query used to create embeddings.'),
      '#default_value' => $this->configuration['max_length'],
      '#step' => 1,
      '#min' => 1,
    ];

    $form['advanced']['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Model'),
      '#group' => 'advanced',
      '#description' => $this->t('The model to use to analyse text.'),
      '#default_value' => $this->configuration['model'],
      '#options' => [
        'text-embedding-ada-002' => 'text-embedding-ada-002',
      ],
    ];

    $form['advanced']['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Debug mode'),
      '#description' => $this->t('Display various chat settings on the front end for debugging/demo purposes.'),
      '#default_value' => $this->configuration['debug'],
    ];

    $form['advanced']['chat'] = [
      '#type' => 'details',
      '#title' => $this->t('Chat'),
      '#collapsible' => TRUE,
      '#open' => FALSE,
    ];

    $form['advanced']['chat']['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Chat model'),
      '#group' => 'advanced',
      '#description' => $this->t('Select which model to use to analyze text. See the <a href="@link">model overview</a> for details about each model.', ['@link' => 'https://platform.openai.com/docs/models/gpt-3.5']),
      '#options' => [
        'gpt-4' => 'gpt-4 (currently beta invite only)',
        'gpt-4-32k' => 'gpt-4-32k (currently beta invite only)',
        'gpt-3.5-turbo' => 'gpt-3.5-turbo',
        'gpt-3.5-turbo-0301' => 'gpt-3.5-turbo-0301',
      ],
      '#default_value' => $this->configuration['chat_model'],
    ];

    $form['advanced']['chat']['temperature'] = [
      '#type' => 'number',
      '#title' => $this->t('Temperature'),
      '#min' => 0,
      '#max' => 2,
      '#step' => 0.1,
      '#default_value' => '0.4',
      '#description' => $this->t('What sampling temperature to use, between 0 and 2. Higher values like 0.8 will make the output more random, while lower values like 0.2 will make it more focused and deterministic.'),
    ];

    $form['advanced']['chat']['max_tokens'] = [
      '#type' => 'number',
      '#title' => $this->t('Max tokens'),
      '#min' => 0,
      '#max' => 4096,
      '#step' => 1,
      '#default_value' => '128',
      '#description' => $this->t('The maximum number of tokens to generate in the completion. The token count of your prompt plus max_tokens cannot exceed the model\'s context length. Most models have a context length of 2048 tokens (except for the newest models, which support 4096).'),
    ];

    $form['advanced']['chat']['chat_system_role'] = [
      '#type' => 'textfield',
      '#title' => $this->t('System message'),
      '#default_value' => $this->configuration['chat_system_role'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['index'] = $form_state->getValue('index');
    $this->configuration['view'] = $form_state->getValue('view');
    $this->configuration['entity_types'] = $form_state->getValue('entity_types');
    $this->configuration['no_results_message'] = $form_state->getValue('no_results_message');
    $this->configuration['error_message'] = $form_state->getValue('error_message');

    $this->configuration['debug'] = $form_state->getValue(['advanced', 'debug']);
    $this->configuration['top_k'] = $form_state->getValue(['advanced', 'top_k']);
    $this->configuration['score_threshold'] = $form_state->getValue(['advanced', 'score_threshold']);
    $this->configuration['max_length'] = $form_state->getValue(['advanced', 'max_length']);
    $this->configuration['model'] = $form_state->getValue(['advanced', 'model']);

    $this->configuration['chat_model'] = $form_state->getValue(['advanced', 'chat', 'model']);
    $this->configuration['temperature'] = $form_state->getValue(['advanced', 'chat', 'temperature']);
    $this->configuration['max_tokens'] = $form_state->getValue(['advanced', 'chat', 'max_tokens']);
    $this->configuration['chat_system_role'] = $form_state->getValue(['advanced', 'chat', 'chat_system_role']);
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $block = [];
    $form_state = new FormState();
    $form_state
      ->addBuildInfo('block_id', $this->getPluginId())
      ->addBuildInfo('chat_config', $this->configuration);
    $block['form'] = $this->formBuilder->buildForm(ChatForm::class, $form_state);

    if ($this->configuration['debug']) {
      $block['debug'] = [
        '#type' => 'details',
        '#title' => $this->t('Debug info'),
        '#collapsible' => TRUE,
        '#open' => FALSE,
        'settings' => [
          '#type' => 'html_tag',
          '#tag' => 'pre',
          '#value' => '',
        ],
      ];

      // Array of config keys to ignore as they are not chat specific.
      $keys_to_ignore = [
        'id',
        'label',
        'label_display',
        'provider',
        'debug',
        'no_results_message',
        'error_message',
        'no_response_message',
      ];
      foreach ($this->configuration as $setting => $value) {
        if (!in_array($setting, $keys_to_ignore)) {
          $value = is_array($value) ? implode(', ', $value) : $value;
          $block['debug']['settings']['#value'] .= "\n" . $setting . ": " . $value;
        }
      }
    }
    return $block;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 0;
  }

}

<?php

namespace Drupal\search_api_ai\Plugin\Block;

use Drupal\Core\Block\BlockBase;
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
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['index'] = $form_state->getValue('index');
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $form_state = new FormState();
    $form_state
      ->addBuildInfo('block_id', $this->getPluginId())
      ->addBuildInfo('index', $this->configuration['index']);
    return $this->formBuilder->buildForm(ChatForm::class, $form_state);
  }

}

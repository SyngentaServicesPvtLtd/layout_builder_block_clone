<?php

namespace Drupal\layout_builder_block_clone\Form;

use Drupal\block_content\Entity\BlockContent;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Ajax\CloseDialogCommand;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\Messenger;
use Drupal\Core\StringTranslation\TranslationManager;
use Drupal\layout_builder\LayoutTempstoreRepositoryInterface;
use Drupal\layout_builder\SectionStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Drupal\Core\Ajax\AjaxFormHelperTrait;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\layout_builder\Controller\LayoutRebuildTrait;
use Drupal\layout_builder\LayoutBuilderHighlightTrait;

/**
 * Class CloneLayoutBlockForm
 *
 * Provides a form for clone a layout block.
 *
 * @package Drupal\layout_builder_block_clone\Form
 */
class CloneLayoutBlockForm extends ConfirmFormBase {

  use AjaxFormHelperTrait;
  use LayoutBuilderHighlightTrait;
  use LayoutRebuildTrait;

  /**
   * The layout tempstore repository.
   *
   * @var \Drupal\layout_builder\LayoutTempstoreRepositoryInterface
   */
  protected $layoutTempstoreRepository;

  /**
   * The section storage.
   *
   * @var \Drupal\layout_builder\SectionStorageInterface
   */
  protected $sectionStorage;

  /**
   * The field delta.
   *
   * @var int
   */
  protected $delta;

  /**
   * The current region.
   *
   * @var string
   */
  protected $region;

  /**
   * The UUID of the block being removed.
   *
   * @var string
   */
  protected $uuid;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity type définition.
   *
   * @var \Drupal\Core\Entity\EntityTypeInterface
   */
  protected $entityTypeDefinition;

  /**
   * The string translation manager.
   *
   * @var \Drupal\Core\StringTranslation\TranslationManager
   */
  protected $stringTranslationManager;

  /**
   * Event dispatcher service.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\Messenger
   */
  protected $messenger;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * CloneLayoutBlockForm constructor.
   *
   * @param \Drupal\layout_builder\LayoutTempstoreRepositoryInterface $layout_tempstore_repository
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\Core\StringTranslation\TranslationManager $string_translation
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   * @param \Drupal\Core\Messenger\Messenger $messenger
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function __construct(LayoutTempstoreRepositoryInterface $layout_tempstore_repository,
                              EntityTypeManagerInterface $entity_type_manager, TranslationManager $string_translation,
                              EventDispatcherInterface $eventDispatcher, Messenger $messenger, ModuleHandlerInterface $module_handler) {
    $this->layoutTempstoreRepository = $layout_tempstore_repository;
    $this->entityTypeManager = $entity_type_manager;
    $this->stringTranslationManager = $string_translation;
    $this->eventDispatcher = $eventDispatcher;
    $this->messenger = $messenger;
    $this->entityTypeDefinition = $entity_type_manager->getDefinition('block_content');
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('layout_builder.tempstore_repository'),
      $container->get('entity_type.manager'),
      $container->get('string_translation'),
      $container->get('event_dispatcher'),
      $container->get('messenger'),
      $container->get('module_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    $label = $this->sectionStorage
      ->getSection($this->delta)
      ->getComponent($this->uuid)
      ->getPlugin()
      ->label();

    return $this->t('Are you sure you want to clone the %label block?', ['%label' => $label]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Clone');
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'layout_builder_block_clone.clone_block_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, SectionStorageInterface $section_storage = NULL, $delta = NULL, $region = NULL, $uuid = NULL) {
    $this->sectionStorage = $section_storage;
    $this->delta = $delta;
    $this->uuid = $uuid;
    $this->region = $region;

    $form['status_messages'] = [
      '#type' => 'status_messages',
      '#weight' => -1000,
    ];

    $form['copy_subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Clone block "Block description"'),
      '#maxlength' => 128,
      '#size' => 128,
      '#required' => TRUE,
    ];

    $form = parent::buildForm($form, $form_state);

    if ($this->isAjax()) {
      $form['actions']['submit']['#ajax']['callback'] = '::ajaxSubmit';
      $form['actions']['cancel']['#attributes']['class'][] = 'dialog-cancel';
      $target_highlight_id = !empty($this->uuid) ? $this->blockUpdateHighlightId($this->uuid) : $this->sectionUpdateHighlightId($delta);
      $form['#attributes']['data-layout-builder-target-highlight-id'] = $target_highlight_id;
    }

    // Mark this as an administrative page for JavaScript ("Back to site" link).
    $form['#attached']['drupalSettings']['path']['currentPathIsAdmin'] = TRUE;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function successfulAjaxSubmit(array $form, FormStateInterface $form_state) {
    return $this->rebuildAndClose($this->sectionStorage);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return $this->sectionStorage->getLayoutBuilderUrl();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Extract block content uuid.
    $original_section = $this->sectionStorage->getSection($this->delta);
    $component = $original_section->getComponent($this->uuid);
    $entity_info = $component->getPluginId();
    $entity_info = explode(':', $entity_info);
    $entity_type = $entity_info[0];
    $entity = NULL;

    switch ($entity_type) {
      case 'inline_block':
        $config = $component->get('configuration');
        $block_serialized = $config['block_serialized'];
        $entity = unserialize($block_serialized);
        if(!$entity instanceof BlockContent) {
          $revision_id = $config['block_revision_id'];
          $entity = $this->entityTypeManager->getStorage('block_content')->loadRevision($revision_id);
        }
        break;
      case 'block_content':
        $entity = $this->entityTypeManager->getStorage($entity_type)->loadByProperties(['uuid' => $entity_info[1]]);
        $entity = reset($entity);
        break;
    }

    // Check if eck is Block Content and clone it.
    if($entity instanceof BlockContent) {
      // Check if entity clone module is enabled.
      $entity_clone = $this->moduleHandler->moduleExists('entity_clone');
      if($entity_clone) {
        /** @var \Drupal\entity_clone\EntityClone\EntityCloneInterface $entity_clone_handler */
        $entity_clone_handler = $this->entityTypeManager->getHandler($this->entityTypeDefinition->id(), 'entity_clone');
        if ($this->entityTypeManager->hasHandler($this->entityTypeDefinition->id(), 'entity_clone_form')) {
          $entity_clone_form_handler = $this->entityTypeManager->getHandler($this->entityTypeDefinition->id(), 'entity_clone_form');
        }

        $properties = [];
        if (isset($entity_clone_form_handler) && $entity_clone_form_handler) {
          $properties = $entity_clone_form_handler->getValues($form_state);
        }
      }

      // Create block duplicate.
      $cloned_entity = $entity->createDuplicate();

      // If entity clone module is used dispatch events and clone via handler.
      if($entity_clone) {
        $this->eventDispatcher->dispatch(\Drupal\entity_clone\Event\EntityCloneEvents::PRE_CLONE, new \Drupal\entity_clone\Event\EntityCloneEvent($entity, $cloned_entity, $properties));
        $cloned_entity = $entity_clone_handler->cloneEntity($entity, $cloned_entity, $properties);
        $this->eventDispatcher->dispatch(\Drupal\entity_clone\Event\EntityCloneEvents::POST_CLONE, new \Drupal\entity_clone\Event\EntityCloneEvent($entity, $cloned_entity, $properties));
      }

      // Set new subject to duplicated eck.
      $label_key = $this->entityTypeManager->getDefinition($this->entityTypeDefinition->id())->getKey('label');
      if ($label_key && $cloned_entity->hasField($label_key)) {
        $cloned_entity->set($label_key, $form_state->getValue('copy_subject'));
        $cloned_entity->setReusable();
        $cloned_entity->save();
      }

      // Get success msg.
      $message = $this->stringTranslationManager->translate('The entity <em>@entity (@entity_id)</em> of type <em>@type</em> was cloned: <em>@cloned_entity (@cloned_entity_id)</em> .', [
        '@entity' => $entity->label(),
        '@entity_id' => $entity->id(),
        '@type' => $entity->getEntityTypeId(),
        '@cloned_entity' => $cloned_entity->label(),
        '@cloned_entity_id' => $cloned_entity->id(),
      ]);
    }

    $this->layoutTempstoreRepository->set($this->sectionStorage);

    $response = $this->rebuildLayout($this->sectionStorage);
    $response->addCommand(new MessageCommand($message));
    $response->addCommand(new CloseDialogCommand('#drupal-off-canvas'));

    return $response;
  }

  /**
   * Check access for layout block clone.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface|NULL $section_storage
   * @param null $delta
   * @param null $region
   * @param null $uuid
   *
   * @return \Drupal\Core\Access\AccessResultAllowed|\Drupal\Core\Access\AccessResultForbidden
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  public function checkAccess(SectionStorageInterface $section_storage = NULL, $delta = NULL, $region = NULL, $uuid = NULL) {
    // Get config.
    $original_section = $section_storage->getSection($delta);
    $component = $original_section->getComponent($uuid);
    $id = explode(':', $component->getPluginId());

    // Allow access only for block content.
    $allowed = [
      'inline_block' => 1,
      'block_content' => 1
    ];

    if(isset($allowed[$id[0]])) {
      return AccessResult::allowed();
    }

    // If this is other type deni access!
    return AccessResult::forbidden();
  }
}


<?php

namespace Drupal\content_moderation\Plugin\Field;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Field\FieldItemList;

/**
 * A computed field that provides a content entity's moderation state.
 *
 * It links content entities to a moderation state configuration entity via a
 * moderation state content entity.
 */
class ModerationStateFieldItemList extends FieldItemList {

  /**
   * Gets the moderation state ID linked to a content entity revision.
   *
   * @return string|null
   *   The moderation state ID linked to a content entity revision.
   */
  protected function getModerationStateId() {
    $entity = $this->getEntity();

    /** @var \Drupal\content_moderation\ModerationInformationInterface $moderation_info */
    $moderation_info = \Drupal::service('content_moderation.moderation_information');
    if (!$moderation_info->shouldModerateEntitiesOfBundle($entity->getEntityType(), $entity->bundle())) {
      return NULL;
    }

    // Existing entities will have a corresponding content_moderation_state
    // entity associated with them.
    if (!$entity->isNew() && $content_moderation_state = $this->loadContentModerationStateRevision($entity)) {
      return $content_moderation_state->moderation_state->value;
    }

    // It is possible that the bundle does not exist at this point. For example,
    // the node type form creates a fake Node entity to get default values.
    // @see \Drupal\node\NodeTypeForm::form()
    $workflow = $moderation_info->getWorkFlowForEntity($entity);
    return $workflow ? $workflow->getTypePlugin()->getInitialState($workflow, $entity)->id() : NULL;
  }

  /**
   * Load the content moderation state revision associated with an entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity the content moderation state entity will be loaded from.
   *
   * @return \Drupal\content_moderation\ContentModerationStateInterface|null
   *   The content_moderation_state revision or FALSE if none exists.
   */
  protected function loadContentModerationStateRevision(ContentEntityInterface $entity) {
    $moderation_info = \Drupal::service('content_moderation.moderation_information');
    $content_moderation_storage = \Drupal::entityTypeManager()->getStorage('content_moderation_state');

    $revisions = \Drupal::service('entity.query')->get('content_moderation_state')
      ->condition('content_entity_type_id', $entity->getEntityTypeId())
      ->condition('content_entity_id', $entity->id())
      // Ensure the correct revision is loaded in scenarios where a revision is
      // being reverted.
      ->condition('content_entity_revision_id', $entity->isNewRevision() ? $entity->getLoadedRevisionId() : $entity->getRevisionId())
      ->condition('workflow', $moderation_info->getWorkflowForEntity($entity)->id())
      ->allRevisions()
      ->sort('revision_id', 'DESC')
      ->execute();
    if (empty($revisions)) {
      return NULL;
    }

    /** @var \Drupal\content_moderation\ContentModerationStateInterface $content_moderation_state */
    $content_moderation_state = $content_moderation_storage->loadRevision(key($revisions));
    if ($entity->getEntityType()->hasKey('langcode')) {
      $langcode = $entity->language()->getId();
      if (!$content_moderation_state->hasTranslation($langcode)) {
        $content_moderation_state->addTranslation($langcode);
      }
      if ($content_moderation_state->language()->getId() !== $langcode) {
        $content_moderation_state = $content_moderation_state->getTranslation($langcode);
      }
    }
    return $content_moderation_state;
  }

  /**
   * {@inheritdoc}
   */
  public function get($index) {
    if ($index !== 0) {
      throw new \InvalidArgumentException('An entity can not have multiple moderation states at the same time.');
    }
    $this->computeModerationFieldItemList();
    return isset($this->list[$index]) ? $this->list[$index] : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getIterator() {
    $this->computeModerationFieldItemList();
    return parent::getIterator();
  }

  /**
   * Recalculate the moderation field item list.
   */
  protected function computeModerationFieldItemList() {
    // Compute the value of the moderation state.
    $index = 0;
    if (!isset($this->list[$index]) || $this->list[$index]->isEmpty()) {

      $moderation_state = $this->getModerationStateId();
      // Do not store NULL values in the static cache.
      if ($moderation_state) {
        $this->list[$index] = $this->createItem($index, $moderation_state);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onChange($delta) {
    $entity = $this->getEntity();

    /** @var \Drupal\content_moderation\ModerationInformationInterface $content_moderation_info */
    $content_moderation_info = \Drupal::service('content_moderation.moderation_information');
    $workflow = $content_moderation_info->getWorkflowForEntity($entity);

    $current_state_id = $this->list[0]->value;

    // Change the entity's default revision flag and the publishing status only
    // if the new workflow state is a valid one.
    if ($workflow->hasState($current_state_id)) {
      /** @var \Drupal\content_moderation\ContentModerationState $current_state */
      $current_state = $workflow->getState($current_state_id);

      // This entity is default if it is new, a new translation, the default
      // revision state, or the default revision is not published.
      $update_default_revision = $entity->isNew()
        || $entity->isNewTranslation()
        || $current_state->isDefaultRevisionState()
        || !$content_moderation_info->isDefaultRevisionPublished($entity);

      $entity->isDefaultRevision($update_default_revision);

      // Update publishing status if it can be updated and if it needs updating.
      $published_state = $current_state->isPublishedState();
      if (($entity instanceof EntityPublishedInterface) && $entity->isPublished() !== $published_state) {
        $published_state ? $entity->setPublished() : $entity->setUnpublished();
      }
    }

    parent::onChange($delta);
  }

}

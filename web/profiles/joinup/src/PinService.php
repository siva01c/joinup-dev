<?php

declare(strict_types = 1);

namespace Drupal\joinup;

use Drupal\joinup_group\Entity\GroupInterface;
use Drupal\joinup_group\Entity\PinnableGroupContentInterface;
use Drupal\rdf_entity\Entity\Rdf;

/**
 * A service to handle pinned entities.
 */
class PinService implements PinServiceInterface {

  /**
   * {@inheritdoc}
   */
  public function isEntityPinned(PinnableGroupContentInterface $entity, ?GroupInterface $group = NULL) {
    return $entity->isPinned($group);
  }

  /**
   * {@inheritdoc}
   */
  public function setEntityPinned(PinnableGroupContentInterface $entity, GroupInterface $group, bool $pinned) {
    if ($pinned) {
      $entity->pin($group);
    }
    else {
      $entity->unpin($group);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getGroupsWherePinned(PinnableGroupContentInterface $entity) {
    return Rdf::loadMultiple($entity->getPinnedGroupIds());
  }

}

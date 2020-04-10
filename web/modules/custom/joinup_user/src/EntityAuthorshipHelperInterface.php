<?php

declare(strict_types = 1);

namespace Drupal\joinup_user;

/**
 * Interface for services to retrieve the entities that were created by a user.
 */
interface EntityAuthorshipHelperInterface {

  /**
   * Returns the entity IDs that are authored by the given user.
   *
   * @param int|string $user_id
   *   The ID of the user that is the author of the entities.
   *
   * @return array
   *   An associative array of entity IDs, keyed by entity type ID.
   *
   * @throws \Drupal\search_api\SearchApiException
   *    Thrown if an error occurred during the search.
   */
  public function getEntityIdsAuthoredByUser($user_id): array;

}

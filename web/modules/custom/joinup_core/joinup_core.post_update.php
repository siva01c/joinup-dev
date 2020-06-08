<?php

/**
 * @file
 * Post update functions for the Joinup core module.
 */

declare(strict_types = 1);

use Drupal\redirect\Entity\Redirect;

/**
 * Update solution, release, distribution and community content aliases.
 */
function joinup_core_post_update_0106100(array &$sandbox): string {
  if (!isset($sandbox['entity_ids'])) {
    // First rebuild solution aliases.
    $sandbox['entity_ids']['rdf_entity'] = \Drupal::entityQuery('rdf_entity')->condition('rid', 'solution')->execute();
    // Then generate the asset release aliases.
    $sandbox['entity_ids']['rdf_entity'] += \Drupal::entityQuery('rdf_entity')->condition('rid', 'asset_release')->execute();
    // Finally, generate the distribution aliases.
    $sandbox['entity_ids']['rdf_entity'] += \Drupal::entityQuery('rdf_entity')->condition('rid', 'asset_distribution')->execute();
    $gc_bundles = ['custom_page', 'discussion', 'document', 'event', 'news'];
    $sandbox['entity_ids']['node'] = \Drupal::entityQuery('node')
      ->condition('type', $gc_bundles, 'IN')
      ->execute();
    $sandbox['current'] = 0;
    $sandbox['max'] = count($sandbox['entity_ids']['rdf_entity']) + count($sandbox['entity_ids']['node']);
  }

  $entity_type = empty($sandbox['entity_ids']['rdf_entity']) ? 'node' : 'rdf_entity';

  $entity_storage = \Drupal::entityTypeManager()->getStorage($entity_type);
  $pathalias_manager = \Drupal::getContainer()->get('path_alias.manager');
  $pathauto_generator = \Drupal::getContainer()->get('pathauto.generator');

  $regex_patterns = [
    'solution' => '#/collection/[^/]*/solution/[^/]*#',
    'asset_release' => '#/collection/[^/]*/solution/[^/]*/release/[^/]*#',
    'asset_distribution' => '#/collection/[^/]*/solution/[^/]*/distribution/[^/]*#',
    'custom_page' => '#/collection/[^/]*(?:/solution/[^/]*)/[^/]*#',
    'discussion' => '#/collection/[^/]*(?:/solution/[^/]*)/discussion/[^/]*#',
    'document' => '#/collection/[^/]*(?:/solution/[^/]*)/document/[^/]*#',
    'event' => '#/collection/[^/]*(?:/solution/[^/]*)/event/[^/]*#',
    'news' => '#/collection/[^/]*(?:/solution/[^/]*)/news/[^/]*#',
  ];

  $ids = array_slice($sandbox['entity_ids'][$entity_type], 0, 50);
  foreach ($entity_storage->loadMultiple(array_values($ids)) as $entity) {
    $sandbox['current']++;
    $old_alias = $pathalias_manager->getAliasByPath($entity->toUrl()->toString());
    $new_alias = $pathauto_generator->createEntityAlias($entity, 'insert');
    if ($old_alias === $new_alias['alias']) {
      continue;
    }

    if (preg_match($regex_patterns[$entity->bundle()], $new_alias['alias']) === FALSE) {
      throw new \Exception("'{$new_alias['alias']}' does not match the expected pattern.");
    }

    Redirect::create([
      'redirect_source' => $old_alias,
      'redirect_redirect' => 'internal:' . $new_alias['alias'],
      'language' => 'und',
      'status_code' => '301',
    ])->save();
  }

  $sandbox['#finished'] = $sandbox['current'] > $sandbox['max'] ? 1 : (float) $sandbox['current'] / (float) $sandbox['max'];
  return "Processed {$sandbox['current']} out of {$sandbox['max']}.";
}

/**
 * Fix the value predicates of the policy domain values.
 */
function joinup_core_post_update_0106101(): string {
  $query = <<<QUERY
DELETE { GRAPH ?g { ?s <http://joinup.eu/voc/policy-domain> ?o } }
INSERT { GRAPH ?g { ?s <http://policy_domain> ?o } }
WHERE { GRAPH ?g { ?s <http://joinup.eu/voc/policy-domain> ?o } }
QUERY;

  $results = \Drupal::getContainer()->get('sparql.endpoint')->query($query);
  $current_result = $results->current();
  $current_result = reset($current_result);
  return $current_result->getValue();
}

<?php

/**
 * @file
 * Post update functions for the Joinup core module.
 */

declare(strict_types = 1);

/**
 * Delete all persistent aliases to ensure that they will be rebuilt.
 */
function joinup_core_post_update_0106500(): void {
  \Drupal::keyValue('pathauto_state.rdf_entity')->deleteAll();
  \Drupal::keyValue('pathauto_state.node')->deleteAll();
}

/**
 * Remove path alias duplicates.
 */
function joinup_core_post_update_0106501(?array &$sandbox = NULL): string {
  $db = \Drupal::database();
  if (!isset($sandbox['duplicate_pids'])) {
    // Get all duplicate path alias IDs.
    $sandbox['duplicate_pids'] = $db->query("SELECT p.id
    FROM {path_alias} p
    LEFT JOIN (
      -- This sub-query returns all alias duplicates of English aliases.
      SELECT
        MAX(id) AS valid_id,
        COUNT(*) AS duplicates_count,
        path
      FROM {path_alias}
      WHERE langcode = 'en'
      GROUP BY path
    ) valid_aliases ON p.path = valid_aliases.path
    WHERE valid_aliases.duplicates_count > 1
    AND valid_aliases.valid_id IS NOT NULL
    AND p.id <> valid_aliases.valid_id
    -- Only select English aliases.
    AND p.langcode = 'en'")->fetchCol();
    $sandbox['progress'] = 0;
    $sandbox['total'] = count($sandbox['duplicate_pids']);
  }

  if ($to_delete = array_splice($sandbox['duplicate_pids'], 0, 1000)) {
    $db->delete('path_alias_revision')
      ->condition('id', $to_delete, 'IN')
      ->execute();
    $db->delete('path_alias')
      ->condition('id', $to_delete, 'IN')
      ->execute();
  }
  $sandbox['progress'] += count($to_delete);

  if ($sandbox['#finished'] = (int) empty($sandbox['duplicate_pids'])) {
    \Drupal::entityTypeManager()->getStorage('path_alias')->resetCache();
  }

  return "Removed {$sandbox['progress']}/{$sandbox['total']}";
}

/**
 * Update aliases for entities with the old alias.
 */
function joinup_core_post_update_0106502(?array &$sandbox = NULL): string {
  $rdf_storage = \Drupal::entityTypeManager()->getStorage('rdf_entity');
  $node_storage = \Drupal::entityTypeManager()->getStorage('node');
  if (empty($sandbox['entity_ids'])) {
    // We process first the collections, solutions and releases because they're
    // parents for the rest of entities and their alias is used in children
    // alias computing.
    $sandbox['entity_ids']['rdf_entity'] = $rdf_storage->getQuery()->condition('rid', 'collection')->execute();
    $sandbox['entity_ids']['rdf_entity'] += $rdf_storage->getQuery()->condition('rid', 'solution')->execute();
    $sandbox['entity_ids']['rdf_entity'] += $rdf_storage->getQuery()->condition('rid', 'asset_release')->execute();
    $sandbox['entity_ids']['rdf_entity'] += $rdf_storage->getQuery()->condition('rid', [
      'collection',
      'solution',
      'asset_release',
    ], 'NOT IN')->execute();
    $sandbox['entity_ids']['node'] = $node_storage->getQuery()->execute();
    $sandbox['count'] = 0;
    $sandbox['max'] = count($sandbox['entity_ids']['rdf_entity']) + count($sandbox['entity_ids']['node']);
  }

  if (empty($sandbox['entity_ids']['rdf_entity'])) {
    $storage = $node_storage;
    $entity_ids = array_splice($sandbox['entity_ids']['node'], 0, 100);
  }
  else {
    $storage = $rdf_storage;
    $entity_ids = array_splice($sandbox['entity_ids']['rdf_entity'], 0, 100);
  }

  $alias_generator = \Drupal::getContainer()->get('pathauto.generator');
  foreach ($storage->loadMultiple($entity_ids) as $entity) {
    if ($entity->bundle() === 'asset_release' && $entity->field_isr_release_number->isEmpty()) {
      // There are a number of releases that do not have a release number, even
      // though it is a mandatory field. Aliases fail to be created for these
      // entities. These will be handled in ISAICP-6217.
      // @see https://citnet.tech.ec.europa.eu/CITnet/jira/browse/ISAICP-6217
      continue;
    }
    $alias_generator->updateEntityAlias($entity, 'bulkupdate');
  }

  $sandbox['count'] += count($entity_ids);
  $sandbox['#finished'] = (int) (empty($sandbox['entity_ids']['rdf_entity']) && empty($sandbox['entity_ids']['node']));
  return "Processed {$sandbox['count']}/{$sandbox['max']}";
}

/**
 * Clean up the validation graphs.
 */
function joinup_core_post_update_0106503(array &$sandbox): void {
  $query = <<<QUERY
SELECT DISTINCT ?g 
   WHERE { GRAPH ?g {?s ?p ?o} } 
ORDER BY ?g
QUERY;

  $connection = \Drupal::getContainer()->get('sparql.endpoint');
  $graphs = $connection->query($query);
  foreach ($graphs as $graph) {
    $uri = $graph->g->getUri();
    if (strpos($uri, 'http://adms-validator/') === 0) {
      $connection->query("CLEAR GRAPH <$uri>");
    }
  }
}

/**
 * Add creation time to entities solutions that lack it.
 */
function joinup_core_post_update_0106504(array &$sandbox): void {
  // Query the solutions without created date and their provenance records
  // corresponding created date.
  $query = <<<QUERY
SELECT DISTINCT ?graph ?id ?created
WHERE {
  GRAPH ?graph {
    ?id ?p ?o .
    ?id a <http://www.w3.org/ns/dcat#Dataset>
    FILTER NOT EXISTS {?id <http://purl.org/dc/terms/issued> ?created__value} .
    FILTER NOT EXISTS {?id <http://purl.org/dc/terms/isVersionOf> ?field_isr_is_version_of__target_id}
  }
  ?provenance_id a <http://www.w3.org/ns/prov#Activity> .
  ?provenance_id <http://purl.org/dc/terms/issued> ?created .
  ?provenance_id <http://www.w3.org/ns/prov#generated> ?id
}
QUERY;

  $database = \Drupal::getContainer()->get('sparql.endpoint');
  $results = $database->query($query);
  $ids_to_clear = [];

  foreach ($results as $result) {
    $graph = $result->graph->getUri();
    $id = $result->id->getUri();
    $ids_to_clear[] = $id;
    $created = $result->created->toRdfPhp();
    $value = $created['value'];
    $type = $created['datatype'];

    $insert_query = <<<QUERY
WITH <{$graph}>
INSERT { <$id> <http://purl.org/dc/terms/issued> "{$value}"^^<{$type}> }
QUERY;
    $database->query($insert_query);
  }

  \Drupal::entityTypeManager()->getStorage('rdf_entity')->resetCache($ids_to_clear);
}

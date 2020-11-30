<?php

/**
 * @file
 * Post update functions for the Joinup core module.
 */

declare(strict_types = 1);

use Drupal\joinup_featured\FeaturedContentInterface;
use Drupal\sparql_entity_storage\SparqlGraphStoreTrait;
use EasyRdf\Graph;

/**
 * Migrate site wide featured content to meta entities.
 */
function joinup_core_post_update_0106600(): string {
  $count = [];
  $entity_type_manager = \Drupal::entityTypeManager();

  foreach (['node', 'rdf_entity'] as $entity_type_id) {
    $count[$entity_type_id] = 0;
    $storage = $entity_type_manager->getStorage($entity_type_id);
    $entity_ids = $storage
      ->getQuery()
      ->condition('field_site_featured', TRUE)
      ->execute();

    foreach ($storage->loadMultiple($entity_ids) as $entity) {
      if ($entity instanceof FeaturedContentInterface) {
        $entity->feature();
        $count[$entity_type_id]++;
      }
    }
  }

  return 'Featured entities: ' . http_build_query($count, '', ', ');
}

/**
 * Update the EIRA SKOS file and its references.
 */
function joinup_core_post_update_0106601(array &$sandbox): void {
  $graphs = [
    'http://joinup.eu/solution/draft',
    'http://joinup.eu/solution/published',
  ];
  $connection = \Drupal::getContainer()->get('sparql.endpoint');
  foreach ($graphs as $graph) {
    $update_query = <<<QUERY
WITH <{$graph}>
DELETE { ?entity_id <http://purl.org/dc/terms/type> <http://data.europa.eu/dr8/Ontologies> }
INSERT { ?entity_id <http://purl.org/dc/terms/type> <http://data.europa.eu/dr8/Ontology> }
WHERE { ?entity_id <http://purl.org/dc/terms/type> <http://data.europa.eu/dr8/Ontologies> }
QUERY;
    $connection->query($update_query);
  }

  $graph_name = 'http://eira_skos';
  $connection->query("DEFINE sql:log-enable 3 CLEAR GRAPH <$graph_name>;");
  $graph_store = SparqlGraphStoreTrait::createGraphStore();
  $filepath = realpath(__DIR__ . '/../../../../resources/fixtures/EIRA_SKOS.rdf');
  $graph = new Graph($graph_name);
  $graph->parseFile($filepath);
  $graph_store->insert($graph);
}

/**
 * Ensure node access records are marked to be rebuilt.
 */
function joinup_core_post_update_0106602(): void {
  // There are new node access grants offered by the joinup_group module.
  node_access_needs_rebuild(TRUE);
}

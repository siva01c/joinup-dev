<?php

declare(strict_types = 1);

namespace Drupal\joinup_search\Plugin\search_api\processor;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\joinup_group\JoinupGroupHelper;
use Drupal\node\NodeInterface;
use Drupal\rdf_entity\RdfInterface;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adds a published/unpublished boolean field.
 *
 * @SearchApiProcessor(
 *   id = "joinup_entity_published",
 *   label = @Translation("Published"),
 *   description = @Translation("Logical flag indicating if the entity is published according to Joinup policies."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 * )
 */
class JoinupEntityPublished extends ProcessorPluginBase {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new plugin instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(array $configuration, string $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function supportsIndex(IndexInterface $index) {
    $supported_entity_types = ['node', 'rdf_entity', 'user'];
    foreach ($index->getDatasources() as $datasource) {
      if (in_array($datasource->getEntityTypeId(), $supported_entity_types)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource) {
      $definition = [
        'label' => $this->t('Published'),
        'description' => $this->t('If the entity is published according to Joinup policies.'),
        'type' => 'boolean',
        'is_list' => FALSE,
        'processor_id' => $this->getPluginId(),
      ];
      $properties['published'] = new ProcessorProperty($definition);
    }

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item) {
    $rdf_storage = $this->entityTypeManager->getStorage('rdf_entity');
    $object = $item->getOriginalObject()->getValue();
    $published = TRUE;
    if ($object instanceof NodeInterface) {
      $published = $object->isPublished();

      if ($published) {
        // The entity can be published only if the parent entity is published.
        $published = FALSE;

        // Load the parent from the entity storage cache rather than relying
        // on the copy that is present in $object->og_audience->entity since
        // this might be stale. This ensures that if the parent has been
        // published in this request we will act on the actual updated state.
        $parent_id = $object->get(JoinupGroupHelper::getGroupField($object))->target_id;
        if (!empty($parent_id)) {
          $parent = $rdf_storage->load($parent_id);
          if (!empty($parent) && $parent->isPublished()) {
            $published = TRUE;
          }
        }
      }
    }
    elseif ($object instanceof RdfInterface) {
      $published = $object->isPublished();
    }
    elseif ($object instanceof UserInterface) {
      $published = $object->isActive();
    }

    $fields = $this->getFieldsHelper()->filterForPropertyPath($item->getFields(), NULL, 'published');
    foreach ($fields as $field) {
      $field->addValue($published);
    }
  }

}

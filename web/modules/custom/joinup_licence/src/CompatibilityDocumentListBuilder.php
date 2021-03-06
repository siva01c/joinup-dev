<?php

declare(strict_types = 1);

namespace Drupal\joinup_licence;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Routing\RedirectDestinationInterface;
use Drupal\joinup_licence\Entity\CompatibilityDocument;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a list controller for the compatibility document entity type.
 */
class CompatibilityDocumentListBuilder extends EntityListBuilder {

  /**
   * The redirect destination service.
   *
   * @var \Drupal\Core\Routing\RedirectDestinationInterface
   */
  protected $redirectDestination;

  /**
   * The licence compatibility plugin manager.
   *
   * @var \Drupal\joinup_licence\JoinupLicenceCompatibilityRulePluginManager
   */
  protected $pluginManager;

  /**
   * Constructs a new CompatibilityDocumentListBuilder object.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   The entity storage class.
   * @param \Drupal\Core\Routing\RedirectDestinationInterface $redirect_destination
   *   The redirect destination service.
   * @param \Drupal\joinup_licence\JoinupLicenceCompatibilityRulePluginManager $plugin_manager
   *   The licence compatibility rule plugin manager.
   */
  public function __construct(EntityTypeInterface $entity_type, EntityStorageInterface $storage, RedirectDestinationInterface $redirect_destination, JoinupLicenceCompatibilityRulePluginManager $plugin_manager) {
    parent::__construct($entity_type, $storage);
    $this->redirectDestination = $redirect_destination;
    $this->pluginManager = $plugin_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): CompatibilityDocumentListBuilder {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('redirect.destination'),
      $container->get('plugin.manager.joinup_licence_compatibility_rule')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    try {
      CompatibilityDocument::populate();
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('An error occurred while trying to populate the compatibility documents: %error', [
        '%error' => $e->getMessage(),
      ]));
    }

    return ['table' => parent::render()];
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['id'] = $this->t('ID');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\joinup_licence\Entity\CompatibilityDocumentInterface $entity */
    $row['id'] = $entity->id();
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity): array {
    $operations = parent::getDefaultOperations($entity);
    $destination = $this->redirectDestination->getAsArray();
    foreach ($operations as $key => $operation) {
      $operations[$key]['query'] = $destination;
    }
    return $operations;
  }

}

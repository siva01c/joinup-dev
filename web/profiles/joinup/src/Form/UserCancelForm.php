<?php

namespace Drupal\joinup\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\ConfirmFormHelper;
use Drupal\Core\Form\FormStateInterface;
use Drupal\joinup_core\JoinupRelationManagerInterface;
use Drupal\user\Form\UserCancelForm as CoreUserCancelForm;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Overrides the default implementation of the form to delete a user account.
 *
 * Deletion of a user account will be denied when the user is the sole owner of
 * a collection.
 */
class UserCancelForm extends CoreUserCancelForm {

  /**
   * The relation manager service.
   *
   * @var \Drupal\joinup_core\JoinupRelationManagerInterface
   */
  protected $relationManager;

  /**
   * Instantiates a new UserCancelForm class.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity_manager
   *   The entity manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\joinup_core\JoinupRelationManagerInterface $relation_manager
   *   The Joinup relation manager.
   */
  public function __construct(EntityInterface $entity_manager, EntityTypeBundleInfoInterface $entity_type_bundle_info, TimeInterface $time, JoinupRelationManagerInterface $relation_manager) {
    parent::__construct($entity_manager, $entity_type_bundle_info, $time);

    $this->relationManager = $relation_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('joinup_core.relations_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Prepare a list of groups where the user is the sole owner.
    $groups = $this->relationManager->getGroupsWhereSoleOwner($this->entity);
    $group_data = [];

    if (!empty($groups)) {
      foreach ($groups as $group) {
        $group_data[$group->bundle()][] = $group->toLink($group->label());
      }

      $rdf_storage = $this->entityTypeManager->getStorage('rdf_type');
      foreach (['collection', 'solution'] as $bundle) {
        $bundle_type = $rdf_storage->load($bundle);
        if (!empty($group_data[$bundle])) {
          $form[$bundle] = [
            '#theme' => 'item_list',
            '#items' => $group_data[$bundle],
            '#title' => $bundle_type->getCountLabel(count($group_data[$bundle])),
            '#weight' => 0,
          ];
        }
      }

      // No access to the 'Cancel' button should be given if the user is the
      // sole owner of a group.
      $form['actions']['submit']['#access'] = FALSE;
      $form['actions'] = [
        '#type' => 'actions',
        // @see \Drupal\Core\Entity\ContentEntityConfirmFormBase::actions()
        'cancel' => ConfirmFormHelper::buildCancelLink($this, $this->getRequest()),
        '#weight' => 10,
      ];

      // Show a nicer message when the user is trying to delete its own account.
      if ($this->currentUser()->id() === $this->entity->id()) {
        $form['pre_warning'] = [
          '#markup' => $this->t('Dear @name,<br />when processing your request to delete your account, we noticed that you are the sole owner of these groups:', [
            '@name' => $this->entity->get('field_user_first_name')->value,
          ]),
          '#weight' => -5,
        ];
        $form['post_warning'] = [
          '#markup' => $this->t('Before removing this account, please verify and take action to modify the owner of the groups mentioned above.'),
          '#weight' => 5,
        ];
      }
      else {
        $form['warning'] = [
          '#markup' => $this->t('User @name cannot be deleted as they are currently the sole owner of these groups:', [
            '@name' => $this->entity->getAccountName(),
          ]),
          '#weight' => -10,
        ];
      }

      // Remove the 'This action cannot be undone as the user is unable to
      // delete the user at this point.
      unset($form['description']);
      return $form;
    }

    return parent::buildForm($form, $form_state);
  }

}

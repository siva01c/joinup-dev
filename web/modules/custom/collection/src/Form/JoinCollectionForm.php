<?php

declare(strict_types = 1);

namespace Drupal\collection\Form;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\og\Entity\OgMembership;
use Drupal\og\MembershipManagerInterface;
use Drupal\og\OgMembershipInterface;
use Drupal\og\OgRoleInterface;
use Drupal\rdf_entity\RdfInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A simple form with a button to join or leave a collection.
 */
class JoinCollectionForm extends FormBase {

  /**
   * CSS classes that will make a link look like a button.
   */
  const LINK_BUTTON_CLASSES = [
    'button',
    'button--blue-light',
    'mdl-button',
    'mdl-js-button',
    'mdl-button--raised',
    'mdl-js-ripple-effect',
    'mdl-button--accent',
  ];

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The membership manager service.
   *
   * @var \Drupal\og\MembershipManagerInterface
   */
  protected $membershipManager;

  /**
   * The access manager service.
   *
   * @var \Drupal\Core\Access\AccessManagerInterface
   */
  protected $accessManager;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a JoinCollectionForm.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\og\MembershipManagerInterface $membership_manager
   *   The membership manager service.
   * @param \Drupal\Core\Access\AccessManagerInterface $access_manager
   *   The access manager service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, MembershipManagerInterface $membership_manager, AccessManagerInterface $access_manager, MessengerInterface $messenger) {
    $this->entityTypeManager = $entity_type_manager;
    $this->membershipManager = $membership_manager;
    $this->accessManager = $access_manager;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('og.membership_manager'),
      $container->get('access_manager'),
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'join_collection_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, AccountProxyInterface $user = NULL, RdfInterface $collection = NULL): array {
    $form['#access'] = $this->access();

    $user = $this->entityTypeManager->getStorage('user')->load($user->id());
    $form['collection_id'] = [
      '#type' => 'value',
      '#value' => $collection->id(),
    ];
    $form['user_id'] = [
      '#type' => 'value',
      '#value' => $user->id(),
    ];

    // In case the user is not a member or does not have a pending membership,
    // give the possibility to request one.
    if (empty($membership)) {
      $form['join'] = [
        '#attributes' => [
          'class' => self::LINK_BUTTON_CLASSES,
        ],
        '#type' => 'submit',
        '#value' => $this->t('Join this collection'),
      ];
    }
    // If the user has an active membership, they can cancel it as well.
    elseif ($membership->getState() === OgMembershipInterface::STATE_ACTIVE) {
      $parameters = ['rdf_entity' => $collection->id()];
      if ($this->accessManager->checkNamedRoute('collection.leave_confirm_form', $parameters)) {
        $form['leave'] = [
          '#type' => 'link',
          '#title' => $this->t('Leave this collection'),
          '#url' => Url::fromRoute('collection.leave_confirm_form', $parameters),
          '#attributes' => [
            'class' => ['use-ajax'],
            'data-dialog-type' => 'modal',
            'data-dialog-options' => Json::encode(['width' => 'auto']),
          ],
        ];
      }
      else {
        $form['leave'] = [
          '#markup' => $this->t("You cannot leave the %collection collection", ['%collection' => $collection->label()]),
        ];
      }
      $form['#attached']['library'][] = 'core/drupal.ajax';
    }
    // If the user has a pending membership, do not allow to request a new one.
    elseif ($membership->getState() === OgMembershipInterface::STATE_PENDING) {
      $form['pending'] = [
        '#type' => 'link',
        '#title' => $this->t('Membership is pending'),
        '#url' => Url::fromRoute('<current>', [], ['fragment' => 'pending']),
        '#attributes' => [
          'class' => array_merge(self::LINK_BUTTON_CLASSES, ['button--small']),
        ],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $collection_id = $form_state->getValue('collection_id');
    $collection = $this->entityTypeManager->getStorage('rdf_entity')->load($collection_id);

    // Only authenticated users can join a collection.
    $user_id = $form_state->getValue('user_id');
    /** @var \Drupal\user\UserInterface $user */
    $user = $this->entityTypeManager->getStorage('user')->load($user_id);
    if ($user->isAnonymous()) {
      $form_state->setErrorByName('user', $this->t('<a href=":login">Sign in</a> or <a href=":register">register</a> to change your group membership.', [
        ':login' => $this->urlGenerator->generateFromRoute('user.login'),
        ':register' => $this->urlGenerator->generateFromRoute('user.register'),
      ]));
    }

    $membership = $this->getUserNonBlockedMembership($user, $collection);
    // Make sure the user is not already a member.
    if (!empty($membership)) {
      $form_state->setErrorByName('collection', $this->t('You already are a member of this collection.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $collection_id = $form_state->getValue('collection_id');

    /** @var \Drupal\rdf_entity\RdfInterface $collection */
    $collection = $this->entityTypeManager->getStorage('rdf_entity')->load($collection_id);
    $user_id = $form_state->getValue('user_id');

    /** @var \Drupal\user\UserInterface $user */
    $user = $this->entityTypeManager->getStorage('user')->load($user_id);
    $role_id = $collection->getEntityTypeId() . '-' . $collection->bundle() . '-' . OgRoleInterface::AUTHENTICATED;

    /** @var \Drupal\og\OgRoleInterface $og_role */
    $og_role = $this->entityTypeManager->getStorage('og_role')->load($role_id);
    $state = $collection->get('field_ar_closed')->first()->value ? OgMembershipInterface::STATE_PENDING : OgMembershipInterface::STATE_ACTIVE;

    /** @var \Drupal\og\OgMembershipInterface $membership */
    $membership = $this->entityTypeManager->getStorage('og_membership')->create([
      'type' => OgMembershipInterface::TYPE_DEFAULT,
    ]);
    $membership
      ->setOwner($user)
      ->setGroup($collection)
      ->setState($state)
      ->setRoles([$og_role])
      ->save();

    $parameters = ['%collection' => $collection->getName()];
    $message = $state === OgMembership::STATE_ACTIVE ?
      $this->t('You are now a member of %collection.', $parameters) :
      $this->t('Your membership to the %collection collection is under approval.', $parameters);
    $this->messenger->addStatus($message);
  }

  /**
   * Access check for the form.
   *
   * @return bool
   *   True if the form can be access, false otherwise.
   */
  public function access(): bool {
    return $this->currentUser()->isAuthenticated() && $this->getRouteMatch()->getRouteName() !== 'collection.leave_confirm_form';
  }

  /**
   * Returns a membership of the user that is active or pending.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user entity.
   * @param \Drupal\rdf_entity\RdfInterface $collection
   *   The group entity.
   *
   * @return \Drupal\og\OgMembershipInterface|null
   *   The membership of the user or null.
   */
  protected function getUserNonBlockedMembership(UserInterface $user, RdfInterface $collection): ?OgMembershipInterface {
    $membership = $this->membershipManager->getMembership($collection, $user, [
      OgMembershipInterface::STATE_ACTIVE,
      OgMembershipInterface::STATE_PENDING,
    ]);
    return $membership;
  }

}

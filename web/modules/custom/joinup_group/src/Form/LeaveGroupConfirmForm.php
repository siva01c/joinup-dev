<?php

declare(strict_types = 1);

namespace Drupal\joinup_group\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\EventSubscriber\MainContentViewSubscriber;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\og\MembershipManagerInterface;
use Drupal\og\Og;
use Drupal\rdf_entity\RdfInterface;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form for users that want to revoke their collection membership.
 */
class LeaveGroupConfirmForm extends ConfirmFormBase {

  /**
   * The group that is about to be abandoned by the user.
   *
   * @var \Drupal\rdf_entity\RdfInterface
   */
  protected $group;

  /**
   * The membership manager service.
   *
   * @var \Drupal\og\MembershipManagerInterface
   */
  protected $membershipManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a LeaveCollectionConfirmForm.
   *
   * @param \Drupal\og\MembershipManagerInterface $membershipManager
   *   The membership manager service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(MembershipManagerInterface $membershipManager, EntityTypeManagerInterface $entityTypeManager) {
    $this->membershipManager = $membershipManager;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('og.membership_manager'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'leave_collection_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Leave :group', [
      ':group' => $this->group->bundle(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t("Are you sure you want to leave the %label :type?<br />By leaving the :type you will be no longer able to publish content in it or receive notifications from it.", [
      '%label' => $this->group->getName(),
      ':type' => $this->group->bundle(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('entity.rdf_entity.canonical', [
      'rdf_entity' => $this->group->id(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?RdfInterface $rdf_entity = NULL): array {
    $this->group = $rdf_entity;
    $form = parent::buildForm($form, $form_state);
    $user = $this->currentUser();

    if ($membership = $this->membershipManager->getMembership($this->group, $user->id())) {
      $admin_role_id = $this->group->getEntityTypeId() . '-' . $this->group->bundle() . '-' . 'administrator';
      if ($membership->hasRole($admin_role_id)) {
        $administrators = $this->membershipManager->getGroupMembershipsByRoleNames($this->group, ['administrator']);
        if (count($administrators) === 1 && $user->id() === $membership->getOwnerId()) {
          $form['description']['#markup'] = $this->t('You are owner of this :type. Before you leave this :type, you should transfer the ownership to another member.', [
            ':type' => $this->group->bundle(),
          ]);
          $form['actions']['submit']['#access'] = FALSE;
        }
      }
    }

    // In case of a modal dialog, set the cancel button to simply close the
    // dialog.
    if ($this->isModal()) {
      $form['actions']['cancel'] = [
        '#type' => 'button',
        '#value' => $this->getCancelText(),
        '#extra_suggestion' => 'light_blue',
        '#attributes' => [
          'class' => ['button--small', 'dialog-cancel'],
        ],
        // Put the cancel button to the left of the confirmation button so it is
        // consistent with the dialog shown when joining the group.
        '#weight' => -1,
      ];

      $form['actions']['submit']['#extra_suggestion'] = 'light_blue';
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Only authenticated users can leave a group.
    $user = $this->currentUser();
    if ($user->isAnonymous()) {
      $form_state->setErrorByName('user', $this->t('<a href=":login">Sign in</a> or <a href=":register">register</a> to change your group membership.', [
        ':login' => Url::fromRoute('user.login'),
        ':register' => Url::fromRoute('user.register'),
      ]));
    }

    if (!$this->membershipManager->isMember($this->group, $user->id())) {
      $form_state->setErrorByName('group', $this->t('You are not a member of this :type. You cannot leave it.', [
        ':type' => $this->group->bundle(),
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $user_id = $this->currentUser()->id();

    $membership = $this->membershipManager->getMembership($this->group, $user_id);
    $membership->delete();

    // Also remove the user authorship, if case.
    if ($this->group->getOwnerId() === $user_id) {
      $this->group->skip_notification = TRUE;
      $this->group->setOwnerId(0)->save();
    }

    $this->messenger()->addStatus($this->t('You are no longer a member of %label.', [
      '%label' => $this->group->getName(),
    ]));

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  /**
   * Access check for the LeaveCollectionConfirmForm.
   *
   * @param \Drupal\rdf_entity\RdfInterface $rdf_entity
   *   The collection that is on the verge of losing a member.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result object.
   */
  public static function access(RdfInterface $rdf_entity): AccessResultInterface {
    /** @var \Drupal\Core\Session\AccountProxyInterface $account_proxy */
    $account_proxy = \Drupal::service('current_user');

    // Deny access if the user is not logged in.
    if ($account_proxy->isAnonymous()) {
      return AccessResult::forbidden();
    }

    // Only allow access if the current user is a member of the collection.
    $user = User::load($account_proxy->id());
    return AccessResult::allowedIf(Og::isMember($rdf_entity, $user));
  }

  /**
   * Returns whether the form is displayed in a modal.
   *
   * @return bool
   *   TRUE if the form is displayed in a modal.
   *
   * @todo Remove when issue #2661046 is in.
   *
   * @see https://www.drupal.org/node/2661046
   */
  protected function isModal(): bool {
    return $this->getRequest()->query->get(MainContentViewSubscriber::WRAPPER_FORMAT) === 'drupal_modal';
  }

}

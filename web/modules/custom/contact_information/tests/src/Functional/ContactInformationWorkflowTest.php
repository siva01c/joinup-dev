<?php

namespace Drupal\Tests\contact_information\Functional;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\rdf_entity\Entity\Rdf;
use Drupal\Tests\joinup_core\JoinupWorkflowTestBase;

/**
 * Tests the workflow for the contact information entity.
 *
 * @group contact_information
 */
class ContactInformationWorkflowTest extends JoinupWorkflowTestBase {

  /**
   * A non authenticated user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $userAnonymous;

  /**
   * A user with the authenticated role.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $userAuthenticated;

  /**
   * A user with the moderator role.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $userModerator;

  /**
   * A user that will be set as owner of the entity.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $userOwner;

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    $this->userAnonymous = new AnonymousUserSession();
    $this->userAuthenticated = $this->createUserWithRoles();
    $this->userModerator = $this->createUserWithRoles(['moderator']);
    $this->userOwner = $this->createUserWithRoles();
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityType() {
    return 'rdf_entity';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityBundle() {
    return 'contact_information';
  }

  /**
   * Tests the workflow for contact information entities.
   */
  public function testWorkflow() {
    foreach ($this->workflowTransitionsProvider() as $entity_state => $workflow_data) {
      foreach ($workflow_data as $user_var => $transitions) {
        $content = Rdf::create([
          'rid' => 'contact_information',
          'label' => $this->randomMachineName(),
          'field_ci_state' => $entity_state,
        ]);
        $content->save();

        // Override the user to be checked for the allowed transitions.
        $this->userProvider->setUser($this->{$user_var});

        $actual_transitions = $content->get('field_ci_state')->first()->getTransitions();
        $actual_transitions = array_map(function ($transition) {
          return $transition->getId();
        }, $actual_transitions);

        sort($actual_transitions);
        sort($transitions);

        $this->assertEquals($transitions, $actual_transitions, "Transitions do not match for user $user_var, state $entity_state.");
      }
    }
  }

  /**
   * Provides data for transition checks.
   *
   * The structure of the array is:
   * @code
   * $workflow_array = [
   *   'entity_state' => [
   *     'user' => [
   *       'transition',
   *       'transition',
   *     ],
   *   ],
   * ];
   * @code
   * There can be multiple transitions that can lead to a specific state, so
   * the check is being done on allowed transitions.
   */
  public function workflowTransitionsProvider() {
    return [
      'validated' => [
        'userAuthenticated' => [
          'update_published',
          'request_deletion',
        ],
        'userModerator' => [
          'update_published',
          'request_changes',
          'request_deletion',
        ],
      ],
      'in_assessment' => [
        'userAuthenticated' => [
          'update_changes',
        ],
        'userModerator' => [
          'update_changes',
          'approve_changes',
        ],
      ],
    ];
  }

}

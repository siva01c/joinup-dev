@api @tallinn
Feature:
  - As a moderator I am able to set the access policy for dashboard data.
  - As an anonymous or as a regular user I'm able to access the dashboard data
    only when the access policy is set to public.
  - As a moderator or as a Tallinn collection facilitator I'm able to access the
    dashboard data regardless of the access policy.
  - Data returned by the dashboard endpoint is cached and the cache is
    invalidated when any of the report entities or the group entity are updated.

  Scenario: Access and cache test.

    Given users:
      | Username | Roles     |
      | Dinesh   |           |
      | Gilfoyle |           |
      | Jared    | moderator |
    And the following collection user membership:
      | collection                      | user     | roles       |
      | Tallinn Ministerial Declaration | Gilfoyle | facilitator |

    Given I am an anonymous user
    When I go to "/tallinn-dashboard"
    Then I should see the following error message:
      | error messages                                     |
      | Access denied. You must sign in to view this page. |
    And I go to "/admin/config/content/tallinn"
    Then I should see the following error message:
      | error messages                                     |
      | Access denied. You must sign in to view this page. |

    Given I am logged in as Dinesh
    When I go to "/tallinn-dashboard"
    Then the response status code should be 403
    And I go to "/admin/config/content/tallinn"
    Then the response status code should be 403

    Given I am logged in as Gilfoyle
    When I go to "/tallinn-dashboard"
    Then the response status code should be 200
    And I go to "/admin/config/content/tallinn"
    Then the response status code should be 403

    Given I am logged in as Jared
    When I go to "/tallinn-dashboard"
    Then the response status code should be 200
    And I go to "/admin/config/content/tallinn"
    Then the response status code should be 200
    And I should see the heading "Tallinn Settings"
    And the radio button "Restricted (only moderators and Tallinn collection facilitators)" from field "Access to the dashboard data" should be selected

    # Make the dashboard data endpoint public.
    Given I select the radio button "Public"
    When I press "Save configuration"
    Then I should see the following success messages:
      | success messages                  |
      | Permissions successfully updated. |
    And the radio button "Public" from field "Access to the dashboard data" should be selected

    Given I go to "/tallinn-dashboard"
    Then the response status code should be 200

    # Test the Json response caching.
    And the response should be cached

    # Edit the group entity.
    Given I go to the "Tallinn Ministerial Declaration" collection edit form
    And I fill in "Description" with "Hooli"
    When I press "Publish"
    And I go to "/tallinn-dashboard"
    Then the response should not be cached

    But I reload the page
    Then the response should be cached

    # Edit any report.
    Given I go to the tallinn_report content "Malta" edit screen
    And I press "Save"
    When I go to "/tallinn-dashboard"
    Then the response should not be cached

    But I reload the page
    Then the response should be cached

    Given I am logged in as Gilfoyle
    And I go to "/tallinn-dashboard"
    Then the response status code should be 200

    Given I am logged in as Dinesh
    And I go to "/tallinn-dashboard"
    Then the response status code should be 200

    Given I am an anonymous user
    And I go to "/tallinn-dashboard"
    Then the response status code should be 200

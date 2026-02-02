<?php

namespace Webdriver;

use Tests\Support\AcceptanceTester;

class PaidUserCest
{
    public function _before(AcceptanceTester $I)
    {
        $I->loginAsPaid();
    }

    // === POSITIVE TESTS ===

    public function canSeeDashboard(AcceptanceTester $I)
    {
        $I->amOnPage('/');
        $I->see("Today's Todos");
        $I->dontSee('A simple countdown timer for your meditation practice');
        $I->dontSee('Admin');  // Paid users don't see admin link
    }

    public function canCreateActivity(AcceptanceTester $I)
    {
        $I->amOnPage('/mg/');
        // Wait for JavaScript to populate the dropdown
        $I->waitForElementVisible('#activity_select', 10);
        // Select "Add new..." option to create a new activity
        $I->selectOption('#activity_select', '+ Add new...');
        $I->waitForElementVisible('#add_activity_wrapper', 5);
        $I->seeElement('#new_activity_name');
        $I->seeElement('#save_new_activity');
    }

    public function canCreateTodo(AcceptanceTester $I)
    {
        $I->amOnPage('/todos/create.php');
        $I->see('Create New Todo');
        $I->seeElement('#title');
        $I->seeElement('button[type=submit]');

        // Fill in the form with test data
        $todoTitle = 'Test Todo ' . time();
        $I->fillField('#title', $todoTitle);
        $I->fillField('#target_duration_minutes', '5');
        $I->fillField('#target_count', '1');

        // Submit the form
        $I->click('button[type=submit]');

        // Should redirect to dashboard with success message
        $I->seeInCurrentUrl('/?msg=todo_created');
        $I->see("Today's Todos");
    }

    // === NEGATIVE TESTS ===

    public function cannotAccessAdminArea(AcceptanceTester $I)
    {
        $I->amOnPage('/admin/');
        $I->see('Access Denied');
        $I->see('You need administrator privileges to access this page.');
    }
}

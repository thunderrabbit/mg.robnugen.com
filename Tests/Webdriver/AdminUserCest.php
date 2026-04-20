<?php

namespace Webdriver;

use Tests\Support\AcceptanceTester;

class AdminUserCest
{
    public function _before(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
    }

    // === POSITIVE TESTS ===

    public function canSeeAdminDashboard(AcceptanceTester $I)
    {
        $I->amOnPage('/');
        // Dashboard is JS-rendered; wait for the sections to appear rather than asserting immediately
        $I->waitForText("Today's Todos", 10);
        // "My Active Timers" heading is display:none until a timer is running,
        // so assert on the always-visible button in the same section instead.
        $I->waitForText("Start New Timer", 10);
    }

    public function canAccessAdminArea(AcceptanceTester $I)
    {
        $I->amOnPage('/admin/');
        $I->dontSee('Access Denied');
    }

    public function canSeeLoginHistory(AcceptanceTester $I)
    {
        $I->amOnPage('/admin/auth_log.php');
        $I->see('Authentication Log');
        $I->see('Showing last');
        $I->see('entries (newest first)');
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

        // Should redirect to dashboard with success message; dashboard sections load via JS
        $I->seeInCurrentUrl('/?msg=todo_created');
        $I->waitForText("Today's Todos", 10);
    }

    // === NEGATIVE TESTS ===

    public function doesNotSeeWelcomePage(AcceptanceTester $I)
    {
        $I->amOnPage('/');
        $I->dontSee('A simple countdown timer for your meditation practice');
        $I->dontSee('Meiso Gambare helps you:');
    }
}

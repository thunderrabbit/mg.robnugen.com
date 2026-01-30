<?php

namespace Webdriver;

use Tests\Support\AcceptanceTester;

class ProUserCest
{
    public function _before(AcceptanceTester $I)
    {
        $I->loginAsPro();
    }

    // === POSITIVE TESTS ===

    public function canSeeDashboard(AcceptanceTester $I)
    {
        $I->amOnPage('/');
        $I->see('Welcome back,');
        $I->see("Today's Todos");
        $I->dontSee('A simple countdown timer for your meditation practice');
    }

    public function canCreateActivity(AcceptanceTester $I)
    {
        $I->amOnPage('/mg/');
        $I->seeElement('#activity_select');
        // Select "Add new..." option to create a new activity
        $I->selectOption('#activity_select', 'Add new...');
        $I->waitForElementVisible('#add_activity_wrapper', 5);
        $I->seeElement('#new_activity_name');
        $I->seeElement('#save_new_activity');
    }

    // === NEGATIVE TESTS ===

    public function cannotAccessAdminArea(AcceptanceTester $I)
    {
        $I->amOnPage('/admin/');
        $I->see('Access Denied');
        $I->see('You need administrator privileges to access this page.');
    }
}

<?php

namespace Webdriver;

use Tests\Support\AcceptanceTester;

class FreeUserCest
{
    public function _before(AcceptanceTester $I)
    {
        $I->loginAsFree();
    }

    // === POSITIVE TESTS ===

    public function canStartTimer(AcceptanceTester $I)
    {
        $I->amOnPage('/mg/');
        $I->see('Countdown minutes:');
        $I->seeElement('.start');
        $I->click('.start');
        $I->waitForElementVisible('.stop', 5);
        $I->seeElement('.stop');
    }

    public function canStopTimer(AcceptanceTester $I)
    {
        $I->amOnPage('/mg/');
        $I->fillField('#countdown_minutes', '1');
        $I->click('.start');
        $I->waitForElementVisible('.stop', 5);
        $I->click('.stop');
        $I->waitForElementNotVisible('.stop', 5);
    }

    // === NEGATIVE TESTS ===

    public function cannotAccessAdminArea(AcceptanceTester $I)
    {
        $I->amOnPage('/admin/');
        $I->see('Access Denied');
        $I->see('You need administrator privileges to access this page.');
    }

    public function seesWelcomePageNotDashboard(AcceptanceTester $I)
    {
        $I->amOnPage('/');
        $I->see('A simple countdown timer for your meditation practice');
        $I->see('Meiso Gambare helps you:');
        $I->dontSee('Welcome back,');
        $I->dontSee("What's up");
    }
}

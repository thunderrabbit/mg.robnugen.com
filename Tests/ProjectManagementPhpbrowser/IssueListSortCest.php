<?php

namespace ProjectManagementPhpbrowser;

use Tests\Support\AcceptanceTester;

class IssueListSortCest
{
    public function issueListShowsPriorityAndDate(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/projects/view.php?project_id=2');
        $I->seeElement('.issue-priority');
        $I->seeElement('.issue-date');
    }

    public function sortControlsPresent(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/projects/view.php?project_id=2');
        $I->seeElement('.issue-list-controls');
        $I->seeLink('Updated');
        $I->seeLink('Created');
        $I->seeLink('Status');
        $I->seeLink('Title');
        $I->seeLink('Priority');
    }

    public function defaultSortIsUpdatedDesc(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/projects/view.php?project_id=2');
        $I->seeElement('.sort-link-active');
        $I->see('Updated ↓', '.sort-link-active');
    }

    public function sortByTitleAscending(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/projects/view.php?project_id=2&sort=title&dir=asc');
        $I->seeElement('.sort-link-active');
        $I->see('Title ↑', '.sort-link-active');
    }

    public function invalidSortFallsBackToUpdated(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/projects/view.php?project_id=2&sort=nonsense&dir=nope');
        $I->seeElement('.sort-link-active');
        $I->see('Updated ↓', '.sort-link-active');
    }
}

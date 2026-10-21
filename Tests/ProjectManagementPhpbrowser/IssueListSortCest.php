<?php

declare(strict_types=1);

namespace ProjectManagementPhpbrowser;

use Tests\Support\AcceptanceTester;

class IssueListSortCest
{
    public function issueListShowsPriorityAndDate(AcceptanceTester $I): void
    {
        $I->loginAsAdmin();
        $I->amOnPage('/projects/view.php?project_id=2');
        $I->seeElement('.issue-priority');
        $I->seeElement('.issue-date');
    }

    public function sortableHeadersPresent(AcceptanceTester $I): void
    {
        $I->loginAsAdmin();
        $I->amOnPage('/projects/view.php?project_id=2');
        $I->seeElement('table.sortable-table');
        foreach (['#', 'Title', 'Status', 'Priority', 'Open subs', 'Assignee', 'Created', 'Updated'] as $label) {
            $I->see($label, 'table.sortable-table th button');
        }
    }

    public function defaultSortIsUpdatedDesc(AcceptanceTester $I): void
    {
        $I->loginAsAdmin();
        $I->amOnPage('/projects/view.php?project_id=2');
        $I->see('Updated', 'table.sortable-table th[aria-sort="descending"]');
    }

    public function oldSortParamsAreIgnored(AcceptanceTester $I): void
    {
        $I->loginAsAdmin();
        $I->amOnPage('/projects/view.php?project_id=2&sort=nonsense&dir=nope');
        $I->seeResponseCodeIs(200);
        $I->see('Updated', 'table.sortable-table th[aria-sort="descending"]');
    }
}

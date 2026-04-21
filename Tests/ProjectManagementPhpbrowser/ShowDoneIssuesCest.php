<?php

namespace ProjectManagementPhpbrowser;

use Tests\Support\AcceptanceTester;

class ShowDoneIssuesCest
{
    public function adminDoesNotSeeDoneIssueByDefault(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/projects/view.php?project_id=2');
        $I->see('Open Issues', 'h2');
        $I->dontSee('Done Issues');
        $I->dontSee('Admin done issue fixture');
        $I->seeLink('Show done');
    }

    public function adminSeesDoneIssueWithShowDoneFlag(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/projects/view.php?project_id=2&show_done=1');
        $I->see('Open Issues', 'h2');
        $I->see('Done Issues', 'h2');
        $I->see('Admin done issue fixture');
        $I->seeLink('Hide done');
    }

    public function toggleLinkPreservesProjectContext(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/projects/view.php?project_id=2');
        $I->click('Show done');
        $I->seeInCurrentUrl('project_id=2');
        $I->seeInCurrentUrl('show_done=1');
        $I->see('Done Issues', 'h2');
    }
}

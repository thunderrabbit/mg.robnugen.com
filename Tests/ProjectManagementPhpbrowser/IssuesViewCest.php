<?php

namespace ProjectManagementPhpbrowser;

use Tests\Support\AcceptanceTester;

class IssuesViewCest
{
    public function adminSeesOwnIssueDetail(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/issues/view.php?issue_id=4');
        $I->seeInCurrentUrl('/issues/view.php');
        $I->see('Wire up the frontend for issues list');
    }

    public function freeUserCannotAccessIssueDetail(AcceptanceTester $I)
    {
        $I->loginAsFree();
        $I->amOnPage('/issues/view.php?issue_id=4');
        $I->seeInCurrentUrl('/?msg=upgrade_required');
        $I->dontSee('Wire up the frontend for issues list');
    }

    public function adminCannotSeeAnotherAccountsIssue(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/issues/view.php?issue_id=1');
        $I->seeInCurrentUrl('/projects/?msg=issue_not_found');
        $I->see('Issue not found.');
        $I->dontSee('Get current tests working');
    }
}

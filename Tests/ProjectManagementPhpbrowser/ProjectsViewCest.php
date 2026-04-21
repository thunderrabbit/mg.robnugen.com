<?php

namespace ProjectManagementPhpbrowser;

use Tests\Support\AcceptanceTester;

class ProjectsViewCest
{
    public function adminSeesTheirOwnProjectDetail(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/projects/view.php?project_id=2');
        $I->seeInCurrentUrl('/projects/view.php');
        $I->see('administrator test project');
    }

    public function freeUserCannotAccessProjectDetail(AcceptanceTester $I)
    {
        $I->loginAsFree();
        $I->amOnPage('/projects/view.php?project_id=2');
        $I->seeInCurrentUrl('/?msg=upgrade_required');
        $I->dontSee('administrator test project');
    }

    public function adminCannotSeeAnotherAccountsProject(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/projects/view.php?project_id=1');
        $I->seeInCurrentUrl('/projects/?msg=project_not_found');
        $I->see('Project not found.');
        $I->dontSee('mg.robnugen.com');
    }

    public function adminSeesOpenIssuesListOnProjectDetail(AcceptanceTester $I) {
        $I->loginAsAdmin();
        $I->amOnPage('/projects/view.php?project_id=2');
        $I->see('Open Issues');                              // section header
        $I->see('Wire up the frontend for issues list');     // the seeded issue title
    }
}

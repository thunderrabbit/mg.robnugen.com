<?php

namespace ProjectManagementPhpbrowser;

use Tests\Support\AcceptanceTester;

class ProjectsIndexCest
{
    public function adminSeesTheirProject(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/projects/');
        $I->seeInCurrentUrl('/projects/');
        $I->see('Projects', 'h1');
        $I->see('administrator test project');
    }

    public function freeUserCannotSeeProjectsPage(AcceptanceTester $I)
    {
        $I->loginAsFree();
        $I->amOnPage('/projects/');
        $I->seeInCurrentUrl('/?msg=upgrade_required');
        $I->dontSee('administrator test project');
    }
}

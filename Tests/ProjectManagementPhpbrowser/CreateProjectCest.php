<?php

namespace ProjectManagementPhpbrowser;

use Tests\Support\AcceptanceTester;

class CreateProjectCest
{
    public function adminSeesCreateProjectForm(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/projects/create.php');
        $I->seeInCurrentUrl('/projects/create.php');
        $I->see('Create Project', 'h1');
        $I->seeElement('input[name=name]');
        $I->seeElement('textarea[name=description]');
    }

    public function adminCanCreateProjectFromForm(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/projects/create.php');
        $projName = 'Test Project via Form ' . time();
        $I->fillField('name', $projName);
        $I->fillField('description', 'Created by Codeception test.');
        $I->click('Create Project');
        $I->seeInCurrentUrl('/projects/view.php?project_id=');
        $I->see($projName, 'h1');
    }

    public function freeUserCannotAccessCreateForm(AcceptanceTester $I)
    {
        $I->loginAsFree();
        $I->amOnPage('/projects/create.php');
        $I->seeInCurrentUrl('/?msg=upgrade_required');
        $I->dontSee('Create Project', 'h1');
    }

    public function projectsIndexHasNewProjectLink(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/projects/');
        $I->seeLink('+ New Project', '/projects/create.php');
    }
}

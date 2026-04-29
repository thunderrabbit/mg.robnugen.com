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

    public function duplicateNameShowsError(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/projects/create.php');
        $I->see('Create Project', 'h1');
        $I->seeElement('input[name=name]');
        $I->submitForm('form[action="/projects/create.php"]', [
            'name'        => 'CreateProjectCest fixture',
            'description' => 'ignored by the duplicate guard',
        ]);
        $I->seeInCurrentUrl('/projects/create.php');
        $I->see('already exists');
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

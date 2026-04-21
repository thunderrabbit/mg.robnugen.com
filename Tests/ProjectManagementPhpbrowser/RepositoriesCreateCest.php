<?php

namespace ProjectManagementPhpbrowser;

use Tests\Support\AcceptanceTester;

class RepositoriesCreateCest
{
    public function adminSeesCreateRepoForm(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/repositories/create.php');
        $I->seeInCurrentUrl('/repositories/create.php');
        $I->see('Create Repository', 'h1');
        $I->seeElement('input[name=name]');
        $I->seeElement('input[name=url]');
        $I->seeElement('input[name=default_branch]');
        $I->seeElement('button[type=submit]');
    }

    public function freeUserCannotAccessCreateRepo(AcceptanceTester $I)
    {
        $I->loginAsFree();
        $I->amOnPage('/repositories/create.php');
        $I->seeInCurrentUrl('/?msg=upgrade_required');
        $I->dontSee('Create Repository', 'h1');
    }

    public function duplicateNameShowsError(AcceptanceTester $I)
    {
        // 'administrator-test-repo' is seeded for user_id=13 (admin), so a POST
        // with the same name must hit the duplicate-name guard (alert-error).
        $I->loginAsAdmin();
        $I->amOnPage('/repositories/create.php');
        $I->submitForm('form[action="/repositories/create.php"]', [
            'name'           => 'administrator-test-repo',
            'url'            => '',
            'default_branch' => 'main',
        ]);
        $I->seeInCurrentUrl('/repositories/create.php');
        $I->see('already exists');
    }
}

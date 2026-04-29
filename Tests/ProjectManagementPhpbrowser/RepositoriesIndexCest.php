<?php

namespace ProjectManagementPhpbrowser;

use Tests\Support\AcceptanceTester;

class RepositoriesIndexCest
{
    public function adminSeesTheirRepository(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/repositories/');
        $I->seeInCurrentUrl('/repositories/');
        $I->see('Repositories', 'h1');
        $I->see('administrator-test-repo');
        $I->see('default branch: main');
    }

    public function freeUserCannotSeeRepositoriesPage(AcceptanceTester $I)
    {
        $I->loginAsFree();
        $I->amOnPage('/repositories/');
        $I->seeInCurrentUrl('/?msg=upgrade_required');
        $I->dontSee('administrator-test-repo');
    }

    public function adminSeesNewRepoLink(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/repositories/');
        $I->seeLink('+ New Repository', '/repositories/create.php');
    }
}

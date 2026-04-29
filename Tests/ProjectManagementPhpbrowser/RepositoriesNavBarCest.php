<?php

namespace ProjectManagementPhpbrowser;

use Tests\Support\AcceptanceTester;

class RepositoriesNavBarCest
{
    public function adminSeesRepositoriesLinkInNav(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/');
        $I->seeLink('Repositories', '/repositories/');
    }

    public function freeUserDoesNotSeeRepositoriesLinkInNav(AcceptanceTester $I)
    {
        $I->loginAsFree();
        $I->amOnPage('/');
        $I->dontSeeLink('Repositories', '/repositories/');
    }
}

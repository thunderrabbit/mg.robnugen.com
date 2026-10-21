<?php

declare(strict_types=1);

namespace ProjectManagementPhpbrowser;

use Tests\Support\AcceptanceTester;

class RepositoriesNavBarCest
{
    public function adminSeesRepositoriesLinkInNav(AcceptanceTester $I): void
    {
        $I->loginAsAdmin();
        $I->amOnPage('/');
        $I->seeLink('Repositories', '/repositories/');
    }

    public function freeUserDoesNotSeeRepositoriesLinkInNav(AcceptanceTester $I): void
    {
        $I->loginAsFree();
        $I->amOnPage('/');
        $I->dontSeeLink('Repositories', '/repositories/');
    }
}

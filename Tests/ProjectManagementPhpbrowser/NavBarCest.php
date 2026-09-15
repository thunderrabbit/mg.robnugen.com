<?php

namespace ProjectManagementPhpbrowser;

use Tests\Support\AcceptanceTester;

class NavBarCest
{
    public function adminSeesProjectsLinkInNav(AcceptanceTester $I): void
    {
        $I->loginAsAdmin();
        $I->amOnPage('/');
        $I->seeLink('Projects', '/projects/');
    }

    public function freeUserDoesNotSeeProjectsLinkInNav(AcceptanceTester $I): void
    {
        $I->loginAsFree();
        $I->amOnPage('/');
        $I->dontSeeLink('Projects', '/projects/');
    }
}

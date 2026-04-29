<?php

namespace ProjectManagementPhpbrowser;

use Tests\Support\AcceptanceTester;

class HeaderBackButtonCest
{
    public function backLinkIsDirectChildOfHeader(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/projects/view.php?project_id=2');
        $I->seeElement('header.dashboard-header > a.btn-back');
        $I->seeLink('← Back to Projects', '/projects/');
    }

    public function backLinkPrecedesH1(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/projects/view.php?project_id=2');
        // XPath: an <a class="btn-back"> that is a direct child of .dashboard-header
        // AND has an <h1> as a following sibling (i.e., a comes before h1 in document order).
        $I->seeElement(
            '//header[contains(concat(" ", normalize-space(@class), " "), " dashboard-header ")]'
            . '/a[contains(concat(" ", normalize-space(@class), " "), " btn-back ")]'
            . '/following-sibling::h1'
        );
    }

    public function issueViewHasBackAndEdit(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/issues/view.php?issue_id=4');
        $I->seeElement('header.dashboard-header > a.btn-back');
        $I->seeElement('header.dashboard-header > div.header-actions > a');
        $I->see('Edit', 'header.dashboard-header > div.header-actions > a');
    }

    public function issueCreatePageHasBackButton(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/issues/create.php?project_id=2');
        $I->seeElement('header.dashboard-header > a.btn-back');
        $I->see('Back to administrator test project', 'header.dashboard-header > a.btn-back');
    }
}

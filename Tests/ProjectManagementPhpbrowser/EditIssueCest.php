<?php

namespace ProjectManagementPhpbrowser;

use Tests\Support\AcceptanceTester;

class EditIssueCest
{
    public function adminSeesEditLinkAndForm(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/issues/view.php?issue_id=4');
        $I->seeLink('Edit', '/issues/edit.php?issue_id=4');
        $I->amOnPage('/issues/edit.php?issue_id=4');
        $I->see('Edit', 'h1');
        $I->seeElement('input[name=title]');
        $I->seeElement('textarea[name=description]');
    }

    public function adminCanEditTitle(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/issues/edit.php?issue_id=4');
        $newTitle = 'Wire up the frontend for issues list (edited ' . time() . ')';
        $I->fillField('title', $newTitle);
        $I->click('Save changes');
        $I->seeInCurrentUrl('/issues/view.php?issue_id=4');
        $I->see($newTitle, 'h1');
    }
}

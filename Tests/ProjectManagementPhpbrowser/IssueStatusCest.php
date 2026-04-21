<?php

namespace ProjectManagementPhpbrowser;

use Tests\Support\AcceptanceTester;

class IssueStatusCest
{
    public function adminSeesStatusPicker(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/issues/view.php?issue_id=4');
        $I->seeElement('select[name=status_id]');
        $I->see('Change status');
    }

    public function adminCanChangeStatus(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/issues/view.php?issue_id=4');
        $I->selectOption('status_id', '2');
        $I->click('Change status');
        $I->seeInCurrentUrl('/issues/view.php?issue_id=4');
        $I->see('In Progress');
        $I->selectOption('status_id', '1');
        $I->click('Change status');
    }
}

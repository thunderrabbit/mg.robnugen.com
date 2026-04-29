<?php

namespace ProjectManagementPhpbrowser;

use Tests\Support\AcceptanceTester;

class AddMemberCest
{
    public function projectDetailHasAddMemberLink(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/projects/view.php?project_id=2');
        $I->seeLink('+ Add Member', '/projects/add-member.php?project_id=2');
    }

    public function adminSeesAddMemberForm(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/projects/add-member.php?project_id=2');
        $I->see('Add Member to');
        $I->seeElement('select[name=member_aiu]');
        $I->see('TestAgent13');
        $I->seeElement('input[name=can_read]');
        $I->seeElement('input[name=can_write]');
        $I->seeElement('button[type=submit]');
    }

    public function freeUserCannotAccessAddMember(AcceptanceTester $I)
    {
        $I->loginAsFree();
        $I->amOnPage('/projects/add-member.php?project_id=2');
        $I->seeInCurrentUrl('/?msg=upgrade_required');
        $I->dontSee('Add Member to', 'h1');
    }
}

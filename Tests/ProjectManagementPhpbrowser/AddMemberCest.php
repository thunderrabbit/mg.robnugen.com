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
        $I->see('Add Member to', 'h1');
        // Project 2 has all account actors already as members, so empty-state appears:
        $I->see('No actors in your account are available to add');
    }

    public function freeUserCannotAccessAddMember(AcceptanceTester $I)
    {
        $I->loginAsFree();
        $I->amOnPage('/projects/add-member.php?project_id=2');
        $I->seeInCurrentUrl('/?msg=upgrade_required');
        $I->dontSee('Add Member to', 'h1');
    }
}

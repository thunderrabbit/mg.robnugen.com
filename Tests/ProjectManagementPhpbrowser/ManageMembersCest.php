<?php

namespace ProjectManagementPhpbrowser;

use Tests\Support\AcceptanceTester;

class ManageMembersCest
{
    public function adminCanAddUpdateAndRemoveMember(AcceptanceTester $I)
    {
        $I->loginAsAdmin();

        // Add: go to add-member form, pick TestAgent13 (aiu=49), check Read only, submit
        $I->amOnPage('/projects/add-member.php?project_id=2');
        $I->selectOption('member_aiu', '49');
        // can_read is checked by default; leave can_write unchecked.
        $I->click('Add Member');
        $I->seeInCurrentUrl('/projects/view.php?project_id=2');
        $I->see('TestAgent13');
        $I->see('Read only');

        // Update: toggle on can_write for TestAgent13, submit.
        $I->submitForm(
            'form.member-perms-form[action="/projects/update-member.php"]',
            [
                'project_id' => 2,
                'member_aiu' => 49,
                'can_read'   => 1,
                'can_write'  => 1,
            ]
        );
        $I->seeInCurrentUrl('/projects/view.php?project_id=2');
        $I->see('TestAgent13');
        $I->see('Read + Write');

        // Remove: submit the remove form.
        $I->submitForm(
            'form.member-remove-form[action="/projects/remove-member.php"]',
            [
                'project_id' => 2,
                'member_aiu' => 49,
            ]
        );
        $I->seeInCurrentUrl('/projects/view.php?project_id=2');
        $I->dontSee('TestAgent13');
    }
}

<?php

declare(strict_types=1);

namespace ProjectManagementPhpbrowser;

use Tests\Support\AcceptanceTester;

class ProjectMembersCest
{
    public function adminSeesMembersSectionOnProjectDetail(AcceptanceTester $I): void
    {
        $I->loginAsAdmin();
        $I->amOnPage('/projects/view.php?project_id=2');
        $I->see('Members', 'h2');
        $I->see('administrator');
        $I->see('Read + Write');
    }

    public function membersListShowsActorType(AcceptanceTester $I): void
    {
        $I->loginAsAdmin();
        $I->amOnPage('/projects/view.php?project_id=2');
        $I->see('(human)');
    }
}

<?php

namespace ProjectManagementPhpbrowser;

use Tests\Support\AcceptanceTester;

class CreateIssueCest
{
    public function adminSeesCreateIssueForm(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/issues/create.php?project_id=2');
        $I->seeInCurrentUrl('/issues/create.php');
        $I->see('New Issue in', 'h1');
        $I->seeElement('input[name=title]');
        $I->seeElement('textarea[name=description]');
        $I->seeElement('select[name=priority]');
    }

    public function adminCanSubmitNewIssue(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/issues/create.php?project_id=2');
        $issueTitle = 'UI-created issue ' . time();
        $I->fillField('title', $issueTitle);
        $I->fillField('description', 'Created by Codeception test.');
        $I->selectOption('priority', 'high');
        $I->click('Create Issue');
        $I->seeInCurrentUrl('/issues/view.php?issue_id=');
        $I->see($issueTitle, 'h1');
    }

    public function projectDetailHasNewIssueLink(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/projects/view.php?project_id=2');
        $I->seeLink('+ New Issue', '/issues/create.php?project_id=2');
    }

    public function freeUserCannotAccessCreateIssueForm(AcceptanceTester $I)
    {
        $I->loginAsFree();
        $I->amOnPage('/issues/create.php?project_id=2');
        $I->seeInCurrentUrl('/?msg=upgrade_required');
        $I->dontSee('New Issue in', 'h1');
    }
}

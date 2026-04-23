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

    public function createFormShowsAssigneeDropdown(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/issues/create.php?project_id=2');
        $I->seeElement('select[name=assignee_aiu]');
        $I->see('— unassigned —', 'select[name=assignee_aiu] option');
        $firstOption = $I->grabTextFrom('select[name=assignee_aiu] option:first-of-type');
        \PHPUnit\Framework\Assert::assertSame('— unassigned —', trim($firstOption));
    }

    public function createWithMemberAssigneePersists(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/issues/create.php?project_id=2');
        $memberAiu = $I->grabAttributeFrom(
            'select[name=assignee_aiu] option:nth-of-type(2)',
            'value'
        );
        \PHPUnit\Framework\Assert::assertNotEmpty($memberAiu, 'Expected a project member option');
        $issueTitle = 'Assignee-member test ' . time();
        $I->fillField('title', $issueTitle);
        $I->fillField('description', 'Assigned to a project member.');
        $I->selectOption('assignee_aiu', $memberAiu);
        $I->selectOption('priority', 'normal');
        $I->click('Create Issue');
        $I->seeInCurrentUrl('/issues/view.php?issue_id=');
        $I->see($issueTitle, 'h1');
        $I->see('assigned to');
        $I->dontSee('unassigned');
    }

    public function createWithUnassignedPersistsAsNull(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/issues/create.php?project_id=2');
        $issueTitle = 'Unassigned test ' . time();
        $I->fillField('title', $issueTitle);
        $I->fillField('description', 'Left assignee as unassigned.');
        $I->selectOption('assignee_aiu', '');
        $I->selectOption('priority', 'normal');
        $I->click('Create Issue');
        $I->seeInCurrentUrl('/issues/view.php?issue_id=');
        $I->see($issueTitle, 'h1');
        $I->see('unassigned');
        $I->dontSee('assigned to');
    }

    public function createWithNonMemberAssigneeShowsError(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/issues/create.php?project_id=2');
        $issueTitle = 'Non-member assignee test ' . time();
        $I->submitForm('form', [
            'project_id'   => '2',
            'title'        => $issueTitle,
            'description'  => 'Should be rejected.',
            'assignee_aiu' => '999999',
            'priority'     => 'normal',
        ]);
        $I->seeInCurrentUrl('/issues/create.php');
        $I->see('Assignee must be a member of this project.', '.alert-error');
        $I->dontSeeInCurrentUrl('/issues/view.php');
    }
}

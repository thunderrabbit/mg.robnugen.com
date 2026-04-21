<?php

namespace ProjectManagementPhpbrowser;

use Tests\Support\AcceptanceTester;

class CommentIssueCest
{
    public function adminSeesCommentForm(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/issues/view.php?issue_id=4');
        $I->seeElement('textarea[name=body]');
        $I->seeLink('← Back to', '/projects/view.php?project_id=2');
        $I->see('Post comment');
    }

    public function adminCanPostCommentAndSeeIt(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/issues/view.php?issue_id=4');
        $body = 'UI comment test ' . time();
        $I->fillField('body', $body);
        $I->click('Post comment');
        $I->seeInCurrentUrl('/issues/view.php?issue_id=4');
        $I->see($body);
    }
}

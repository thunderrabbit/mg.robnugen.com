<?php

namespace ProjectManagementApi;

use Tests\Support\AcceptanceTester;

class IssuesListCest
{
    public function fullKeySeesOwnIssue(AcceptanceTester $I)
    {
        $I->haveHttpHeader('X-API-Key', getenv('MG_TEST_KEY_FULL'));
        $I->sendGET('/api/v1/issues/list?project_id=3');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContains('Test-Full API issue');
    }

    public function fullKeyCanFetchStatusesLookup(AcceptanceTester $I)
    {
        $I->haveHttpHeader('X-API-Key', getenv('MG_TEST_KEY_FULL'));
        $I->sendGET('/api/v1/issues/statuses');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContains('open');
        $I->seeResponseContains('in_progress');
    }

    public function missingKeyGets401(AcceptanceTester $I)
    {
        $I->sendGET('/api/v1/issues/list?project_id=3');
        $I->seeResponseCodeIs(401);
    }
}

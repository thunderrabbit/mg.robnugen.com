<?php

namespace ProjectManagementApi;

use Tests\Support\AcceptanceTester;

class IssuePriorityCest
{
    public function listReturnsPriorityField(AcceptanceTester $I)
    {
        $I->haveHttpHeader('X-API-Key', getenv('MG_TEST_KEY_FULL'));
        $I->sendGET('/api/v1/issues/list?project_id=3');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContains('priority');
        $I->seeResponseContains('normal');
    }

    public function createAcceptsValidPriority(AcceptanceTester $I)
    {
        $I->haveHttpHeader('X-API-Key', getenv('MG_TEST_KEY_FULL'));
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/v1/issues/create', json_encode([
            'project_id' => 3,
            'title'      => 'Priority test issue',
            'priority'   => 'high',
        ]));
        $I->seeResponseCodeIs(201);
        $I->seeResponseContains('"priority":"high"');
    }

    public function createRejectsInvalidPriority(AcceptanceTester $I)
    {
        $I->haveHttpHeader('X-API-Key', getenv('MG_TEST_KEY_FULL'));
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/v1/issues/create', json_encode([
            'project_id' => 3,
            'title'      => 'Invalid priority test',
            'priority'   => 'urgent',
        ]));
        $I->seeResponseCodeIs(400);
    }
}

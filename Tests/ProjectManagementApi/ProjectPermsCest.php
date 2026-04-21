<?php

namespace ProjectManagementApi;

use Tests\Support\AcceptanceTester;

class ProjectPermsCest
{
    public function alphaKeyCannotReadProjects(AcceptanceTester $I)
    {
        $I->haveHttpHeader('X-API-Key', getenv('MG_TEST_KEY_ALPHA'));
        $I->sendGET('/api/v1/projects/list');
        $I->seeResponseCodeIs(403);
        $I->seeResponseContains('can_read_project');
    }

    public function alphaKeyCannotWriteProjects(AcceptanceTester $I)
    {
        $I->haveHttpHeader('X-API-Key', getenv('MG_TEST_KEY_ALPHA'));
        $I->sendPOST('/api/v1/projects/create', ['name' => 'should not matter']);
        $I->seeResponseCodeIs(403);
        $I->seeResponseContains('can_write_project');
    }

    public function alphaKeyCannotReadIssues(AcceptanceTester $I)
    {
        $I->haveHttpHeader('X-API-Key', getenv('MG_TEST_KEY_ALPHA'));
        $I->sendGET('/api/v1/issues/list?project_id=3');
        $I->seeResponseCodeIs(403);
        $I->seeResponseContains('can_read_project');
    }
}

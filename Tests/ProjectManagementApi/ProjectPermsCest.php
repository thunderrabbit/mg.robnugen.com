<?php

declare(strict_types=1);

namespace ProjectManagementApi;

use Tests\Support\AcceptanceTester;

class ProjectPermsCest
{
    public function alphaKeyCannotReadProjects(AcceptanceTester $I): void
    {
        $I->haveHttpHeader('X-API-Key', (string) getenv('MG_TEST_KEY_ALPHA'));
        $I->sendGET('/api/v1/projects/list');
        $I->seeResponseCodeIs(403);
        $I->seeResponseContains('can_read_project');
    }

    public function alphaKeyCannotWriteProjects(AcceptanceTester $I): void
    {
        $I->haveHttpHeader('X-API-Key', (string) getenv('MG_TEST_KEY_ALPHA'));
        $I->sendPOST('/api/v1/projects/create', ['name' => 'should not matter']);
        $I->seeResponseCodeIs(403);
        $I->seeResponseContains('can_write_project');
    }

    public function alphaKeyCannotReadIssues(AcceptanceTester $I): void
    {
        $I->haveHttpHeader('X-API-Key', (string) getenv('MG_TEST_KEY_ALPHA'));
        $I->sendGET('/api/v1/issues/list?project_id=3');
        $I->seeResponseCodeIs(403);
        $I->seeResponseContains('can_read_project');
    }
}

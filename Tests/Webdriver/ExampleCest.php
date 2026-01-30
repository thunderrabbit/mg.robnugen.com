<?php

namespace Webdriver;

use Tests\Support\AcceptanceTester;

/**
 * Example test demonstrating login patterns.
 * Remove or replace this file with actual tests.
 */
class ExampleCest
{
    public function _before(AcceptanceTester $I)
    {
        // Setup code runs before each test
    }

    /**
     * Example: Admin can access the admin dashboard
     */
    public function adminCanAccessAdminDashboard(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage('/admin/');
        // Add assertions for admin dashboard content
        // $I->see('Admin Dashboard');
    }

    /**
     * Example: Pro user can see their dashboard
     */
    public function proUserCanSeeDashboard(AcceptanceTester $I)
    {
        $I->loginAsPro();
        $I->amOnPage('/');
        // Add assertions for pro user dashboard content
        // $I->see('Dashboard');
    }

    /**
     * Example: Free user can start timers
     */
    public function freeUserCanStartTimer(AcceptanceTester $I)
    {
        $I->loginAsFree();
        // Navigate to timer functionality
        // Add assertions for timer functionality
    }
}

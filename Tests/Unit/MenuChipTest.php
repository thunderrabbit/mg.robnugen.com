<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;

/**
 * The count inside the menu's Admin link (mg #851): admins only, never at 0.
 */
class MenuChipTest extends Unit
{
    protected function _before(): void
    {
        // $_SESSION is just an array until session_start(), so this stays session-free.
        $_SESSION = ['csrf_token' => 'menu-token'];
    }

    protected function _after(): void
    {
        unset($_SESSION);
    }

    /**
     * The menu as the layouts include it. It takes the visitor's role from
     * $is_admin and $is_paid when there is no login object to ask.
     */
    private function menu(bool $logged_in, bool $is_admin, int $admin_count): string
    {
        $config = TestSettings::at(dirname(__DIR__, 2));  // project root: real templates/
        $menu = new \Template(
            config: $config,
            is_logged_in: null,
            csrf: new \Security\CSRFProtectaroo(new \Mlaphp\Request()),
            admin_count: $admin_count,
        );
        $menu->setTemplate('partials/menu.tpl.php');
        $menu->set('is_logged_in', $logged_in);
        $menu->set('is_admin', $is_admin);
        return $menu->grabTheGoods();
    }

    public function testAdminWithCountSeesChipInsideTheLinkNamedForScreenReaders(): void
    {
        $this->assertMatchesRegularExpression(
            '#<a href="/admin/" aria-label="Admin, 3 waiting for an admin">Admin\s*'
            . '<span class="AdminCount" aria-hidden="true">3</span></a>#',
            $this->menu(true, true, 3),
        );
    }

    public function testZeroLeavesThePlainAdminLink(): void
    {
        $html = $this->menu(true, true, 0);

        $this->assertStringContainsString('<a href="/admin/">Admin</a>', $html);
        $this->assertStringNotContainsString('AdminCount', $html);
    }

    public function testPlainUserNeverSeesACount(): void
    {
        $html = $this->menu(true, false, 3);

        $this->assertStringContainsString('Logout', $html);
        $this->assertStringNotContainsString('AdminCount', $html);
        $this->assertStringNotContainsString('waiting for an admin', $html);
    }

    public function testLoggedOutVisitorNeverSeesACount(): void
    {
        $html = $this->menu(false, false, 3);

        $this->assertStringNotContainsString('Logout', $html);
        $this->assertStringNotContainsString('AdminCount', $html);
        $this->assertStringNotContainsString('waiting for an admin', $html);
    }
}

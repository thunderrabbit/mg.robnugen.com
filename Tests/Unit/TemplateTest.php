<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;

/**
 * What every Template hands its template whatever the page set (mg #851).
 */
class TemplateTest extends Unit
{
    private string $app_path = '';

    protected function _before(): void
    {
        if (!class_exists(\Config::class)) {
            require_once dirname(__DIR__, 2) . '/classes/ConfigSample.php';
        }
        $this->app_path = sys_get_temp_dir() . '/mg-template-test-' . bin2hex(random_bytes(6));
        mkdir($this->app_path . '/templates', 0700, true);
        file_put_contents(
            $this->app_path . '/templates/count.tpl.php',
            '<?php /** @var int $admin_count */ ?>count=<?= $admin_count ?>',
        );
        // $_SESSION is just an array until session_start(), so this stays session-free.
        $_SESSION = ['csrf_token' => 'template-token'];
    }

    protected function _after(): void
    {
        unlink($this->app_path . '/templates/count.tpl.php');
        rmdir($this->app_path . '/templates');
        rmdir($this->app_path);
        unset($_SESSION);
    }

    private function template(int $admin_count): \Template
    {
        $config = new \Config();
        $config->app_path = $this->app_path;
        $template = new \Template(
            config: $config,
            is_logged_in: null,
            csrf: new \Security\CSRFProtectaroo(new \Mlaphp\Request()),
            admin_count: $admin_count,
        );
        $template->setTemplate('count.tpl.php');
        return $template;
    }

    public function testTheTemplateSeesTheConstructorsAdminCount(): void
    {
        $this->assertSame('count=3', $this->template(3)->grabTheGoods());
    }

    public function testAPageCannotReplaceTheAdminCount(): void
    {
        $template = $this->template(3);
        $template->set('admin_count', 99);

        $this->assertSame('count=3', $template->grabTheGoods());
    }
}

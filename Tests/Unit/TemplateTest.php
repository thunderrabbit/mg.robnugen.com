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
        $this->app_path = sys_get_temp_dir() . '/mg-template-test-' . bin2hex(random_bytes(6));
        mkdir($this->app_path . '/templates', 0700, true);
        file_put_contents(
            $this->app_path . '/templates/count.tpl.php',
            '<?php /** @var int $admin_count */ ?>count=<?= $admin_count ?>',
        );
        // What the Images card writes when there is nothing to show (mg #911).
        file_put_contents(
            $this->app_path . '/templates/nothing.tpl.php',
            '<?php if (false) : ?>never<?php endif; ?>',
        );
        // $_SESSION is just an array until session_start(), so this stays session-free.
        $_SESSION = ['csrf_token' => 'template-token'];
    }

    protected function _after(): void
    {
        unlink($this->app_path . '/templates/count.tpl.php');
        unlink($this->app_path . '/templates/nothing.tpl.php');
        rmdir($this->app_path . '/templates');
        rmdir($this->app_path);
        unset($_SESSION);
    }

    private function template(int $admin_count, string $template_file = 'count.tpl.php'): \Template
    {
        $config = TestSettings::at($this->app_path);
        $template = new \Template(
            config: $config,
            is_logged_in: null,
            csrf: new \Security\CSRFProtectaroo(new \Mlaphp\Request()),
            admin_count: $admin_count,
        );
        $template->setTemplate($template_file);
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

    public function testATemplateThatWritesNothingGivesAnEmptyString(): void
    {
        $this->assertSame('', $this->template(0, 'nothing.tpl.php')->grabTheGoods());
    }

    public function testAMissingTemplateIsNamedInAnError(): void
    {
        $this->assertSame(
            "Error loading template: {$this->app_path}/templates/missing.tpl.php",
            $this->template(0, 'missing.tpl.php')->grabTheGoods(),
        );
    }
}

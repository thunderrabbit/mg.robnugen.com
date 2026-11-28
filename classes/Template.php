<?php

declare(strict_types=1);

class Template
{
    protected string $template_location;

    /** @var array<string, mixed> */
    protected array $vars;

    /**
     * @param \Security\CSRFProtectaroo $csrf Reaches the template as $csrf: a form
     *        writes <?= $csrf->field() ?>, a fetch() caller json_encode($csrf->getToken()).
     * @param int $admin_count What waits for an admin (prepend.php counts it), reaching
     *        the template as $admin_count for the menu's Admin link. Required, so a
     *        page that forgets it fails PHPStan and does not quietly lose the chip.
     */
    public function __construct(
        \Config\Settings $config,
        ?\Auth\IsLoggedIn $is_logged_in,
        private readonly \Security\CSRFProtectaroo $csrf,
        private readonly int $admin_count,
    ) {
        $this->template_location = "{$config->app_path}/templates";

        $this->vars = [];
        if ($is_logged_in) {
            $this->vars['is_logged_in'] = $is_logged_in;
        }
    }

    public function setTemplate(string $template_file): void
    {
        $this->template_location = $this->template_location . "/" . $template_file;
    }

    /**
     * Summary of set
     * @param string $name
     * @param mixed $value mixed so array of file names can be passed in /list/index.php
     * @return void
     */
    public function set(string $name, mixed $value): void
    {
        $this->vars[$name] = $value;
    }

    public function echoToScreen(): void
    {
        echo $this->loadTemplate(); // Display the contents directly to the page
    }

    /**
     * Hand over the rendered template, mate.
     * I'm gonna give it to this guy over here.
     *
     * This function is used to return the rendered template as a string.
     * It is used to get the inner content of what will be sent to a base template.
     * @return string
     */
    public function grabTheGoods(): string
    {
        return $this->loadTemplate();
    }

    protected function loadTemplate(): string
    {
        $charEncode = "UTF-8";
        extract($this->vars);              // Extract the vars to local namespace
        $csrf = $this->csrf;               // After extract(), so a set('csrf') cannot replace it
        $admin_count = $this->admin_count; // Likewise: only prepend.php's count reaches the menu

        // A template may write nothing (a card with nothing to show), so an empty
        // result is no error: only a file that is not there is (mg #911).
        if (!is_file($this->template_location)) {
            return "Error loading template: {$this->template_location}";
        }

        ob_start();                        // Start output buffering

        include($this->template_location);    // Include the file

        $ob_result = ob_get_clean();

        if ($ob_result === false) {
            return "Error loading template: {$this->template_location}";
        }

        return $ob_result;
    }
}

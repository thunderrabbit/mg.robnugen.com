<?php

class Template
{
    protected string $template_location;

    /** @var array<string, mixed> */
    protected array $vars;

    public function __construct(
        \Config $config,
        ?\Auth\IsLoggedIn $is_logged_in = null
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
    public function set(string $name, mixed $value)
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

        ob_start();                        // Start output buffering

        include($this->template_location);    // Include the file

        $ob_result = ob_get_clean();

        // if $ob_result is false, return an error message
        if (empty($ob_result)) {
            return "Error loading template: {$this->template_location}";
        }

        return $ob_result;
    }
}

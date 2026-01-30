<?php

namespace Tests\Support\Helper;

use PHPUnit\Framework\Assert;

// Custom helper methods available in $I

class Acceptance extends \Codeception\Module
{
    /**
     * Switch to newly opened window
     * from https://stackoverflow.com/a/51548896/194309
     *
     * @throws \Codeception\Exception\ModuleException
     */
    public function switchToNewWindow()
    {
        $webdriver = $this->getModule('WebDriver')->webDriver;
        $handles = $webdriver->getWindowHandles();
        $lastWindow = end($handles);
        $webdriver->switchTo()->window($lastWindow);
    }

    /**
     * Fail the test with a custom message
     *
     * @param string $message The failure message to display
     * @throws \PHPUnit\Framework\AssertionFailedError
     */
    public function fail($message = '')
    {
        Assert::fail($message);
    }
}

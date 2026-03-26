<?php

namespace Webdriver;

use Tests\Support\AcceptanceTester;

class InboxByteCounterCest
{
    public function _before(AcceptanceTester $I)
    {
        $I->loginAsTester();
    }

    public function mainTextareaCountsAsciiBytes(AcceptanceTester $I)
    {
        $I->amOnPage('/inbox/');
        $I->seeInTitle('Agent Inbox');
        $I->waitForElementVisible('#message', 10);

        // Type 5 ASCII characters = 5 bytes
        $I->fillField('#message', 'Hello');
        $I->waitForText('5 / 10,240 bytes', 5, '#message-counter');
    }

    public function mainTextareaCountsBytesNotCharacters(AcceptanceTester $I)
    {
        $I->amOnPage('/inbox/');
        $I->seeInTitle('Agent Inbox');
        $I->waitForElementVisible('#message', 10);

        // Emoji 😋 is 4 bytes in UTF-8, but 1 character
        // Use executeJS because ChromeDriver cannot type non-BMP characters via fillField
        $I->executeJS("
            var ta = document.getElementById('message');
            ta.value = '😋';
            ta.dispatchEvent(new Event('input'));
        ");
        $I->waitForText('4 / 10,240 bytes', 5, '#message-counter');

        // Japanese あ is 3 bytes in UTF-8, but 1 character
        $I->executeJS("
            var ta = document.getElementById('message');
            ta.value = 'あ';
            ta.dispatchEvent(new Event('input'));
        ");
        $I->waitForText('3 / 10,240 bytes', 5, '#message-counter');

        // Mix: "aあ😋" = 1 + 3 + 4 = 8 bytes
        $I->executeJS("
            var ta = document.getElementById('message');
            ta.value = 'aあ😋';
            ta.dispatchEvent(new Event('input'));
        ");
        $I->waitForText('8 / 10,240 bytes', 5, '#message-counter');
    }

    public function counterShowsWarningNearLimit(AcceptanceTester $I)
    {
        $I->amOnPage('/inbox/');
        $I->seeInTitle('Agent Inbox');
        $I->waitForElementVisible('#message', 10);

        // 9,217 bytes = just over 90% of 10,240 (threshold is 9,216)
        // Use JS to set the value since fillField with 9K+ chars is slow
        $I->executeJS("
            var ta = document.getElementById('message');
            ta.value = 'x'.repeat(9217);
            ta.dispatchEvent(new Event('input'));
        ");
        $I->waitForElementVisible('.byte-counter-warn', 5);
        $I->seeElement('#message-counter.byte-counter-warn');

        // Over 100%: should have byte-counter-over class
        $I->executeJS("
            var ta = document.getElementById('message');
            ta.value = 'x'.repeat(10241);
            ta.dispatchEvent(new Event('input'));
        ");
        $I->waitForElementVisible('.byte-counter-over', 5);
        $I->seeElement('#message-counter.byte-counter-over');
    }

    public function counterResetsWhenCleared(AcceptanceTester $I)
    {
        $I->amOnPage('/inbox/');
        $I->seeInTitle('Agent Inbox');
        $I->waitForElementVisible('#message', 10);

        $I->fillField('#message', 'Hello');
        $I->waitForText('5 / 10,240 bytes', 5, '#message-counter');

        // Clear the textarea — must use executeJS to trigger input event
        $I->executeJS("
            var ta = document.getElementById('message');
            ta.value = '';
            ta.dispatchEvent(new Event('input'));
        ");
        $I->waitForText('0 / 10,240 bytes', 5, '#message-counter');

        // Warning class should be gone
        $I->dontSeeElement('#message-counter.byte-counter-warn');
        $I->dontSeeElement('#message-counter.byte-counter-over');
    }
}

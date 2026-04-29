<?php

namespace Tests\Support;

/**
 * Inherited Methods
 * @method void wantToTest($text)
 * @method void wantTo($text)
 * @method void execute($callable)
 * @method void expectTo($prediction)
 * @method void expect($prediction)
 * @method void amGoingTo($argumentation)
 * @method void am($role)
 * @method void lookForwardTo($achieveValue)
 * @method void comment($description)
 * @method \Codeception\Lib\Friend haveFriend($name, $actorClass = NULL)
 *
 * @SuppressWarnings(PHPMD)
 */

class AcceptanceTester extends \Codeception\Actor
{
    use _generated\AcceptanceTesterActions;

    // Credentials loaded from environment variables
    public $admin_username;
    public $admin_password;
    public $paid_username;
    public $paid_password;
    public $free_username;
    public $free_password;
    public $tester_username;
    public $tester_password;

    public function __construct(\Codeception\Scenario $scenario)
    {
        parent::__construct($scenario);
        $this->admin_username = getenv('MG_ADMIN_USER');
        $this->admin_password = getenv('MG_ADMIN_PASS');
        $this->paid_username = getenv('MG_PAID_USER');
        $this->paid_password = getenv('MG_PAID_PASS');
        $this->free_username = getenv('MG_FREE_USER');
        $this->free_password = getenv('MG_FREE_PASS');
        $this->tester_username = getenv('MG_TESTER_USER');
        $this->tester_password = getenv('MG_TESTER_PASS');
    }

    /**
     * Login as admin user
     */
    public function loginAsAdmin()
    {
        $this->login($this->admin_username, $this->admin_password);
    }

    /**
     * Login as paid user
     */
    public function loginAsPaid()
    {
        $this->login($this->paid_username, $this->paid_password);
    }

    /**
     * Login as free user
     */
    public function loginAsFree()
    {
        $this->login($this->free_username, $this->free_password);
    }

    /**
     * Login as tester user (Dr_Hilbert_Space_mgTester — has API keys and actors)
     */
    public function loginAsTester()
    {
        $this->login($this->tester_username, $this->tester_password);
    }

    /**
     * Perform login with given credentials
     *
     * @param string $username
     * @param string $password
     * @param bool $expect_success Whether to expect successful login
     */
    public function login($username, $password, $expect_success = true)
    {
        $I = $this;
        $I->amOnPage('/login/');
        $I->see('Log In');
        $I->fillField('#username', $username);
        $I->fillField('#password', $password);
        $I->click('input[type=submit]');

        if ($expect_success) {
            // After successful login, user should not see the login form
            $I->dontSee('Log In', 'h2');
        }
    }

    /**
     * Logout the current user
     */
    public function logout()
    {
        $I = $this;
        $I->amOnPage('/login/logout.php');
    }
}

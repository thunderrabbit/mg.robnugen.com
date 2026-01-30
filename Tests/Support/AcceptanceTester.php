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
    public $pro_username;
    public $pro_password;
    public $free_username;
    public $free_password;

    public function __construct(\Codeception\Scenario $scenario)
    {
        parent::__construct($scenario);
        $this->admin_username = getenv('ADMIN_USER');
        $this->admin_password = getenv('ADMIN_PASS');
        $this->pro_username = getenv('PRO_USER');
        $this->pro_password = getenv('PRO_PASS');
        $this->free_username = getenv('FREE_USER');
        $this->free_password = getenv('FREE_PASS');
    }

    /**
     * Login as admin user
     */
    public function loginAsAdmin()
    {
        $this->login($this->admin_username, $this->admin_password);
    }

    /**
     * Login as pro user
     */
    public function loginAsPro()
    {
        $this->login($this->pro_username, $this->pro_password);
    }

    /**
     * Login as free user
     */
    public function loginAsFree()
    {
        $this->login($this->free_username, $this->free_password);
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

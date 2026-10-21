<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;

/**
 * The parts of IsLoggedIn's login funnel that send no headers: a refused
 * password, the throttle, and resuming from a remember-me cookie. In-memory
 * SQLite stands in for MySQL.
 */
class LoginFunnelTest extends Unit
{
    private const UA = 'Mozilla/5.0 LoginFunnelTest';

    private \PDO $pdo;
    private string $app_path;

    /** @var array<mixed> */
    private array $saved_server;

    /** @var array<mixed> */
    private array $saved_cookie;

    protected function _before(): void
    {
        if (!class_exists(\Config::class)) {
            require_once dirname(__DIR__, 2) . '/classes/ConfigSample.php';
        }
        $this->saved_server = $_SERVER;
        $this->saved_cookie = $_COOKIE;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_USER_AGENT'] = self::UA;

        $this->app_path = sys_get_temp_dir() . '/login-funnel-' . bin2hex(random_bytes(4));
        mkdir($this->app_path);

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            "CREATE TABLE users (user_id INTEGER PRIMARY KEY, username TEXT, password_hash TEXT, role TEXT)"
        );
        $this->pdo->exec("CREATE TABLE user_settings (user_id INTEGER, site_title TEXT, site_subtitle TEXT)");
        $this->pdo->exec(
            "CREATE TABLE cookies (
                cookie_id INTEGER PRIMARY KEY AUTOINCREMENT,
                cookie TEXT NOT NULL UNIQUE,
                user_id INTEGER NOT NULL,
                created_at TEXT,
                last_access TEXT,
                expires_at TEXT NOT NULL,
                user_agent_md5 TEXT
            )"
        );
        $this->pdo->exec(
            "CREATE TABLE login_attempts (
                login_attempt_id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL,
                ip_address BLOB,
                attempted_at TEXT NOT NULL
            )"
        );
        $stmt = $this->pdo->prepare("INSERT INTO users (user_id, username, password_hash, role) VALUES (?, ?, ?, ?)");
        $stmt->execute([7, 'Alice', password_hash('right horse', PASSWORD_DEFAULT), 'admin']);
    }

    protected function _after(): void
    {
        $_SERVER = $this->saved_server;
        $_COOKIE = $this->saved_cookie;
        @unlink($this->app_path . '/auth_log.txt');
        @rmdir($this->app_path);
    }

    private function isLoggedIn(): \Auth\IsLoggedIn
    {
        $config = new \Config();
        $config->app_path = $this->app_path;
        $config->cookie_name = 'mg_test';
        return new \Auth\IsLoggedIn(
            $this->pdo,
            $config,
            new \Database\CookieRepository($this->pdo),
            new \Auth\LoginThrottle($this->pdo),
        );
    }

    private function failures(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM login_attempts");
        $count = $stmt === false ? false : $stmt->fetchColumn();
        return is_int($count) ? $count : -1;
    }

    public function testWrongPasswordIsRefusedAndCounted(): void
    {
        $auth = $this->isLoggedIn();
        $this->assertSame(\Auth\LoginResult::BadCredentials, $auth->attemptPasswordLogin('Alice', 'wrong'));
        $this->assertFalse($auth->isLoggedIn());
        $this->assertSame(1, $this->failures());
    }

    public function testUnknownUsernameLooksTheSameAsAWrongPassword(): void
    {
        $auth = $this->isLoggedIn();
        $this->assertSame(\Auth\LoginResult::BadCredentials, $auth->attemptPasswordLogin('nobody', 'wrong'));
        $this->assertSame(1, $this->failures(), 'guesses at missing names count too');
    }

    public function testThrottledLoginRefusesEvenTheRightPassword(): void
    {
        $auth = $this->isLoggedIn();
        for ($i = 0; $i < \Auth\LoginThrottle::MAX_FAILURES_PER_USERNAME; $i++) {
            $auth->attemptPasswordLogin('alice', 'wrong');
        }
        $this->assertSame(\Auth\LoginResult::Throttled, $auth->attemptPasswordLogin('Alice', 'right horse'));
        $this->assertFalse($auth->isLoggedIn());
    }

    public function testAccentedSpellingIsRefusedBeforeTheLookup(): void
    {
        // MySQL's accent-insensitive collation would match this to Alice;
        // SQLite would not, so what this pins is the refusal and its count.
        $auth = $this->isLoggedIn();
        $this->assertSame(\Auth\LoginResult::BadCredentials, $auth->attemptPasswordLogin('Álice', 'right horse'));
        $this->assertFalse($auth->isLoggedIn());
        $this->assertSame(1, $this->failures(), 'counted against the IP');
    }

    public function testAccentedSpellingsDoNotEscapeTheThrottle(): void
    {
        $auth = $this->isLoggedIn();
        for ($i = 0; $i < \Auth\LoginThrottle::MAX_FAILURES_PER_USERNAME; $i++) {
            $auth->attemptPasswordLogin('alice', 'wrong');
        }
        foreach (['Álice', 'àlice', 'ålice'] as $variant) {
            $this->assertSame(\Auth\LoginResult::BadCredentials, $auth->attemptPasswordLogin($variant, 'right horse'));
        }
        $this->assertSame(\Auth\LoginResult::Throttled, $auth->attemptPasswordLogin('Alice', 'right horse'));
    }

    public function testResumeFromCookieLogsInTheCookiesOwner(): void
    {
        (new \Database\CookieRepository($this->pdo))->issue(7, hash('sha256', 'tok-123'), md5(self::UA), 3600);
        $_COOKIE = ['mg_test' => 'tok-123'];

        $auth = $this->isLoggedIn();
        $auth->resumeFromCookie(new \Mlaphp\Request());

        $this->assertTrue($auth->isLoggedIn());
        $this->assertSame(7, $auth->loggedInID());
        $this->assertSame('Alice', $auth->getLoggedInUsername());
    }

    public function testResumeWithoutACookieIsNobody(): void
    {
        $_COOKIE = [];
        $auth = $this->isLoggedIn();
        $auth->resumeFromCookie(new \Mlaphp\Request());
        $this->assertFalse($auth->isLoggedIn());
    }

    public function testAUsernameAndPasswordInAnyPostDoNotLogIn(): void
    {
        $_POST = ['username' => 'Alice', 'pass' => 'right horse'];
        $_COOKIE = [];
        $auth = $this->isLoggedIn();
        $auth->resumeFromCookie(new \Mlaphp\Request());
        $_POST = [];
        $this->assertFalse($auth->isLoggedIn(), 'only /login/ checks passwords');
    }
}

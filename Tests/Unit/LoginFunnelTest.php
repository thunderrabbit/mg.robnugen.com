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
    private const string UA = 'Mozilla/5.0 LoginFunnelTest';

    private \PDO $pdo;
    private string $app_path;

    /** @var array<mixed> */
    private array $saved_server;

    /** @var array<mixed> */
    private array $saved_cookie;

    protected function _before(): void
    {
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
        $stmt->execute([7, 'Alice', \Auth\PasswordHash::make('right horse'), 'admin']);
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
        $config = TestSettings::at($this->app_path, cookie_name: 'mg_test');
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

    public function testAThrottledLoginLeavesNoRowOfItsOwn(): void
    {
        $auth = $this->isLoggedIn();
        $max = \Auth\LoginThrottle::MAX_FAILURES_PER_USERNAME;
        for ($i = 0; $i < $max; $i++) {
            $auth->attemptPasswordLogin('alice', 'wrong');
        }
        $auth->attemptPasswordLogin('alice', 'wrong');
        $this->assertSame($max, $this->failures(), 'being refused does not lengthen the lock');
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
        new \Database\CookieRepository($this->pdo)->issue(7, hash('sha256', 'tok-123'), md5(self::UA), 3600);
        $_COOKIE = ['__Host-mg_test' => 'tok-123'];

        $auth = $this->isLoggedIn();
        $auth->resumeFromCookie(new \Mlaphp\Request());

        $this->assertTrue($auth->isLoggedIn());
        $this->assertSame(7, $auth->loggedInID());
        $this->assertSame('Alice', $auth->getLoggedInUsername());
    }

    public function testACookieUnderTheOldBareNameIsIgnored(): void
    {
        new \Database\CookieRepository($this->pdo)->issue(7, hash('sha256', 'tok-123'), md5(self::UA), 3600);
        $_COOKIE = ['mg_test' => 'tok-123'];

        $auth = $this->isLoggedIn();
        $auth->resumeFromCookie(new \Mlaphp\Request());

        $this->assertFalse($auth->isLoggedIn(), 'only the __Host- name is read');
    }

    public function testResumeWithoutACookieIsNobody(): void
    {
        $_COOKIE = [];
        $auth = $this->isLoggedIn();
        $auth->resumeFromCookie(new \Mlaphp\Request());
        $this->assertFalse($auth->isLoggedIn());
    }

    private function storedHash(): string
    {
        $stmt = $this->pdo->query("SELECT password_hash FROM users WHERE user_id = 7");
        $hash = $stmt === false ? false : $stmt->fetchColumn();
        return is_string($hash) ? $hash : '';
    }

    public function testAnOldHashIsRehashedWhenItsPasswordIsRight(): void
    {
        $old_hash = password_hash('right horse', PASSWORD_BCRYPT, ['cost' => 4]);
        $this->pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = 7")->execute([$old_hash]);

        // checkPHPHashedPassword() is the part of a login that sends no headers.
        $check = new \ReflectionMethod(\Auth\IsLoggedIn::class, 'checkPHPHashedPassword');
        $this->assertSame(7, $check->invoke($this->isLoggedIn(), 'Alice', 'right horse'));

        $new_hash = $this->storedHash();
        $this->assertNotSame($old_hash, $new_hash);
        $this->assertFalse(\Auth\PasswordHash::needsRehash($new_hash));
        $this->assertTrue(password_verify('right horse', $new_hash), 'a password shorter than the policy still works');
    }

    public function testABcryptHashFromBeforeArgon2idStillLogsIn(): void
    {
        // Made at today's bcrypt cost, the way every hash was before the change.
        $bcrypt_hash = password_hash('right horse', PASSWORD_BCRYPT);
        $this->pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = 7")->execute([$bcrypt_hash]);

        $check = new \ReflectionMethod(\Auth\IsLoggedIn::class, 'checkPHPHashedPassword');
        $this->assertSame(7, $check->invoke($this->isLoggedIn(), 'Alice', 'right horse'));
        $this->assertTrue(password_verify('right horse', $this->storedHash()));
    }

    public function testThatLoginReplacesTheBcryptHashWhereArgon2idIsInUse(): void
    {
        $bcrypt_hash = password_hash('right horse', PASSWORD_BCRYPT);
        $this->pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = 7")->execute([$bcrypt_hash]);

        $check = new \ReflectionMethod(\Auth\IsLoggedIn::class, 'checkPHPHashedPassword');
        $check->invoke($this->isLoggedIn(), 'Alice', 'right horse');

        if (\Auth\PasswordHash::algorithm() === \Auth\PasswordHash::ARGON2ID) {
            $this->assertStringStartsWith('$argon2id$', $this->storedHash());
        } else {
            // A PHP without argon2id has nothing newer to move it to.
            $this->assertSame($bcrypt_hash, $this->storedHash());
        }
    }

    public function testAWrongPasswordLeavesTheOldHashAlone(): void
    {
        $old_hash = password_hash('right horse', PASSWORD_BCRYPT, ['cost' => 4]);
        $this->pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = 7")->execute([$old_hash]);

        $check = new \ReflectionMethod(\Auth\IsLoggedIn::class, 'checkPHPHashedPassword');
        $this->assertSame(0, $check->invoke($this->isLoggedIn(), 'Alice', 'wrong'));
        $this->assertSame($old_hash, $this->storedHash());
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

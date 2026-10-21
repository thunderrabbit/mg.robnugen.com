<?php

/**
 * This file tries to simplify knowing if user is logged in.
 *
 *
 */

namespace Auth;

class IsLoggedIn
{
    private int $who_is_logged_in = 0;

    private string $loggedInUsername = 'YUNOset?'; // default value, should be overwritten if user is logged in
    private string $log_file_path;

    public function __construct(
        private \PDO $di_pdo,
        private \Config $di_config,
        private \Database\CookieRepository $di_cookies,
    ) {
        // Set log file path to project root (parent of wwwroot)
        $this->log_file_path = $di_config->app_path . '/auth_log.txt';
    }

    private function logAuth(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] {$message}\n";
        file_put_contents($this->log_file_path, $log_entry, FILE_APPEND);
    }

    /**
     * Who is this request from? Runs on EVERY request (prepend.php). It only
     * reads the remember-me cookie and never looks at credentials: that is
     * attemptPasswordLogin()'s job, and only the login page calls that.
     */
    public function resumeFromCookie(\Mlaphp\Request $mla_request): void
    {
        $cookie = \Field::string($mla_request->cookie, $this->di_config->cookie_name);
        if ($cookie === '') {
            return;
        }

        $found_user_id = $this->getUserIdForCookieInDatabase(
            cookie: $cookie,
            user_agent: \Field::string($_SERVER, 'HTTP_USER_AGENT')
        );
        if ($found_user_id <= 0) {
            $current_ip = \Field::string($_SERVER, 'REMOTE_ADDR', 'unknown');
            $this->logAuth("user_id: N/A, IP: {$current_ip} - Cookie validation FAILED");
            $this->killCookie();
            return;
        }

        $this->who_is_logged_in = $found_user_id;
        $this->setUsernameOfLoggedInID($found_user_id);
    }

    /**
     * Password login. Called by /login/ on POST and nowhere else, so a stray
     * username/pass pair in some other form is just data, and a wrong
     * password can no longer log a user out of an unrelated page.
     *
     * BadCredentials has no side effects; the caller shows one generic
     * message for it so a guesser cannot learn which usernames exist.
     */
    public function attemptPasswordLogin(string $username, string $password): LoginResult
    {
        $current_ip = \Field::string($_SERVER, 'REMOTE_ADDR', 'unknown');
        $user_id = $this->checkPHPHashedPassword($username, $password);
        if ($user_id <= 0) {
            $this->logAuth("user_id: N/A, IP: {$current_ip} - Password login FAILED (username: {$username})");
            return LoginResult::BadCredentials;
        }

        $this->logAuth("user_id: {$user_id}, IP: {$current_ip} - Password login SUCCESS");
        $this->establishSession($user_id);
        return LoginResult::Success;
    }

    /**
     * The one way to become logged in. EVERY authentication path (password
     * today; whatever comes next) must end by calling this and nothing else,
     * so no path can skip the fixation guard or end up with different
     * persistence from the others.
     */
    private function establishSession(int $user_id): void
    {
        // Fresh privilege level, fresh session id (fixation guard): a
        // pre-set session id must not survive the authentication boundary.
        session_regenerate_id(true);
        // regenerate_id keeps the session data, so a CSRF token minted (or
        // fixated) before login would survive it. Drop it; prepend.php mints
        // a fresh one on the next request.
        unset($_SESSION['csrf_token']);
        $this->setAutoLoginCookie($user_id);
        $this->who_is_logged_in = $user_id;
        $this->setUsernameOfLoggedInID($user_id);
    }

    private string $siteTitle = '';
    private string $siteSubtitle = '';

    private function setUsernameOfLoggedInID(int $user_id): void
    {
        if ($user_id <= 0) {
            return;
        }
        // Change from setUsernameOfLoggedInID to loadUserData effectively

        // Fetch username and settings
        $sql = "
            SELECT u.username, s.site_title, s.site_subtitle
            FROM users u
            LEFT JOIN user_settings s ON u.user_id = s.user_id
            WHERE u.user_id = ?
            LIMIT 1
        ";
        $stmt = $this->di_pdo->prepare($sql);
        $stmt->execute([$user_id]);
        $row = \Db\Rows::all($stmt->fetchAll(\PDO::FETCH_ASSOC))[0] ?? null;

        if ($row !== null) {
            $this->loggedInUsername = \Db\RowValue::stringOrNull($row, 'username') ?? 'ummmmmm wtf';
            $this->siteTitle = \Db\RowValue::stringOrNull($row, 'site_title') ?? '';
            $this->siteSubtitle = \Db\RowValue::stringOrNull($row, 'site_subtitle') ?? '';
        }
    }

    public function getLoggedInUsername(): string
    {
        return $this->loggedInUsername;
    }

    public function getSiteTitle(): string
    {
        return !empty($this->siteTitle) ? $this->siteTitle : 'Meiso Gambare';
    }

    public function getSiteSubtitle(): string
    {
        // NOTE: We don't have access to SEMVER constant here cleanly unless global,
        // but typically this is used in templates where SEMVER is available.
        // For the default, we return the text without version, let template handle version if needed?
        // Proposal said: "Your simple meditation timer"
        return !empty($this->siteSubtitle) ? $this->siteSubtitle : 'Your simple meditation timer';
    }

    public function getUserRole(): string
    {
        if ($this->who_is_logged_in <= 0) {
            return '';
        }

        $stmt = $this->di_pdo->prepare("SELECT `role` FROM `users` WHERE `user_id` = ? LIMIT 1");
        $stmt->execute([$this->who_is_logged_in]);
        $row = \Db\Rows::all($stmt->fetchAll(\PDO::FETCH_ASSOC))[0] ?? null;

        if ($row !== null) {
            return \Db\RowValue::stringOrNull($row, 'role') ?? '';
        }
        return '';
    }

    public function isAdmin(): bool
    {
        return $this->getUserRole() === 'admin';
    }

    public function isPaid(): bool
    {
        return $this->getUserRole() === 'paid';
    }

    private function setAutoLoginCookie(int $user_id): void
    {
        $cookie = \Utilities::randomString(32);

        // Store only the SHA-256 of the token: a leaked DB dump or backup must
        // not contain ready-to-use session tokens. The browser holds the
        // plaintext; lookups hash the presented value. The row carries its own
        // expires_at, so the lifetime is enforced here and not only by the browser.
        $this->di_cookies->issue(
            user_id: $user_id,
            cookie_hash: hash('sha256', $cookie),
            user_agent_md5: md5(\Field::string($_SERVER, 'HTTP_USER_AGENT')),
            lifetime_seconds: $this->di_config->cookie_lifetime,
        );

        $cookie_options = \Auth\CookieOptions::build(
            $this->di_config->domain_name,
            time() + $this->di_config->cookie_lifetime // 30 days
        );
        setcookie($this->di_config->cookie_name, $cookie, $cookie_options);
    }



    /**
     * @return array<mixed> The user_id and password_hash row, or [] when no such user
     */
    private function getIDandPHPHashedPasswordForUsername(string $username): array
    {
        // get password hash
        $stmt = $this->di_pdo->prepare("SELECT `user_id`, `password_hash` FROM `users` WHERE LOWER(`username`) = LOWER(?) LIMIT 1");
        $stmt->execute([$username]);

        return \Db\Rows::all($stmt->fetchAll(\PDO::FETCH_ASSOC))[0] ?? [];
    }
    /**
     * Looks up hashed password for username, and checks it against the password provided
     * @param $username
     * @param $password
     * @return int The user_id, or 0 when the password doesn't match
     */
    private function checkPHPHashedPassword(string $username, string $password): int
    {
        // get password hash
        $user_id_and_hash_array = $this->getIDandPHPHashedPasswordForUsername($username);
        if (!empty($user_id_and_hash_array)) {
            $hashed_password = \Field::string($user_id_and_hash_array, 'password_hash');
            // check it
            if (password_verify($password, $hashed_password)) {
                // password is correct, so this user_id has logged in properly
                $user_id = \Field::int($user_id_and_hash_array, 'user_id');
                // return user_id
                return $user_id;
            }
        } else {
            return 0;
        }

        return 0;
    }


    private function getUserIdForCookieInDatabase(
        string $cookie,
        string $user_agent
    ): int {
        $cookie_hash = hash('sha256', $cookie);
        $user_agent_md5 = md5($user_agent);
        $user_id = $this->di_cookies->findUserId($cookie_hash, $user_agent_md5);
        if ($user_id <= 0) {
            $refused = $this->di_cookies->whyRefused($cookie_hash, $user_agent_md5);
            $who = $refused['user_id'] > 0 ? (string) $refused['user_id'] : 'N/A';
            $current_ip = \Field::string($_SERVER, 'REMOTE_ADDR', 'unknown');
            $this->logAuth("user_id: {$who}, IP: {$current_ip} - Cookie refused: {$refused['reason']}");
        }
        return $user_id;
    }

    public function isLoggedIn(): bool
    {
        return $this->who_is_logged_in > 0;
    }

    public function loggedInID(): int
    {
        return $this->who_is_logged_in;
    }


    /**
     * Sign this user out of every OTHER browser: the remember-me cookie in
     * hand keeps working, everything else is revoked server-side. Call it
     * whenever the password changes. Returns how many sessions were revoked.
     */
    public function revokeOtherSessions(): int
    {
        if ($this->who_is_logged_in <= 0) {
            return 0;
        }
        return $this->di_cookies->revokeAllForUser($this->who_is_logged_in, $this->presentedCookieHash());
    }

    /**
     * SHA-256 of the remember-me cookie this browser sent, or null without one.
     */
    private function presentedCookieHash(): ?string
    {
        $presented = \Field::string($_COOKIE, $this->di_config->cookie_name);
        return $presented !== '' ? hash('sha256', $presented) : null;
    }

    public function logout(): void
    {
        // Revoke this browser's row, not only its cookie: a copied cookie
        // value must stop working once its owner has logged out.
        $cookie_hash = $this->presentedCookieHash();
        if ($cookie_hash !== null) {
            $this->di_cookies->revoke($cookie_hash);
        }
        $this->who_is_logged_in = 0;
        $this->killCookie();
        session_destroy();
        session_start();
        session_regenerate_id();
    }

    private function killCookie(): void
    {
        $cookie_options = \Auth\CookieOptions::build(
            $this->di_config->domain_name,
            time() - 3600
        );
        setcookie($this->di_config->cookie_name, '', $cookie_options);
        $this->who_is_logged_in = 0;
    }
}

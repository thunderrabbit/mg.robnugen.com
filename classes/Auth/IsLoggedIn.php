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
    private ?int $logged_in_aiu = null;

    private string $loggedInUsername = 'YUNOset?'; // default value, should be overwritten if user is logged in
    private string $log_file_path;

    public function __construct(
        private \PDO $di_pdo,
        private \Config $di_config,
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

    public function checkLogin(\Mlaphp\Request $mla_request): void
    {
        $found_user_id = 0;
        $current_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        if(!empty($mla_request->cookie[$this->di_config->cookie_name]))
        {
            $found_user_id = $this->getUserIdForCookieInDatabase(
                cookie: $mla_request->cookie[$this->di_config->cookie_name],
                user_agent: $_SERVER['HTTP_USER_AGENT'] ?? ''
            );
            if(empty($found_user_id))
            {
                $this->logAuth("user_id: N/A, IP: {$current_ip} - Cookie validation FAILED");
                $this->killCookie();
                $this->who_is_logged_in = 0;
                $this->logged_in_aiu = null;
            } else {
                // $this->logAuth("user_id: {$found_user_id}, IP: {$current_ip} - Cookie validation SUCCESS");
                $this->who_is_logged_in = $found_user_id;
            }
        } elseif(!empty($mla_request->post['username']) && !empty($mla_request->post['pass'])) {
            $post_token    = $mla_request->post['csrf_token'] ?? '';
            $session_token = $_SESSION['csrf_token'] ?? '';
            if (!hash_equals($session_token, $post_token)) {
                $this->logAuth("user_id: N/A, IP: {$current_ip} - Login blocked: CSRF token mismatch");
                return;
            }
            $found_user_id = $this->checkPHPHashedPassword($mla_request->post['username'], $mla_request->post['pass']);
            if(empty($found_user_id))
            {
                $this->logAuth("user_id: N/A, IP: {$current_ip} - Password login FAILED (username: {$mla_request->post['username']})");
                $this->killCookie();        // bad login, so kill any cookie
                $this->who_is_logged_in = 0;
            } else {
                $this->logAuth("user_id: {$found_user_id}, IP: {$current_ip} - Password login SUCCESS");
                session_regenerate_id(true); // prevent session fixation
                $this->setAutoLoginCookie($found_user_id);
                $this->who_is_logged_in = $found_user_id;
            }
        }
        // set the session variable for username
        $this->setUsernameOfLoggedInID($this->who_is_logged_in);
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
        $result = $stmt->fetchAll();

        if (count($result) > 0) {
            $this->loggedInUsername = $result[0]['username'] ?? 'ummmmmm wtf';
            $this->siteTitle = $result[0]['site_title'] ?? '';
            $this->siteSubtitle = $result[0]['site_subtitle'] ?? '';
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
        $result = $stmt->fetchAll();

        if (count($result) > 0) {
            return $result[0]['role'] ?? '';
        }
        return '';
    }

    public function isAdmin(): bool
    {
        // Agent sessions never inherit the parent user's elevated role.
        if ($this->logged_in_aiu !== null) {
            return false;
        }
        return $this->getUserRole() === 'admin';
    }

    public function isPaid(): bool
    {
        if ($this->logged_in_aiu !== null) {
            return false;
        }
        return $this->getUserRole() === 'paid';
    }

    private function setAutoLoginCookie(int $user_id, ?int $aiu_id = null):void
    {
        $cookie = \Utilities::randomString(32);

        $record = [
            'user_id' => $user_id,
            'aiu_id' => $aiu_id,
            'cookie' => $cookie,
            'last_access' => date(format: "Y-m-d H:i:s"),
            'user_agent_md5' => md5($_SERVER['HTTP_USER_AGENT'] ?? '')
        ];

        // Insert using native PDO
        $stmt = $this->di_pdo->prepare("INSERT INTO `cookies` (`user_id`, `aiu_id`, `cookie`, `last_access`, `user_agent_md5`) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute(array_values($record));

        $cookie_options = [
            'expires' => time() + $this->di_config->cookie_lifetime, // 30 days
            'path' => '/',
            'domain' => $this->di_config->domain_name,
            'secure' => true,   // HTTPS only
            'httponly' => true, // not accessible via JavaScript
            'samesite' => 'Lax' // Lax allows cookie on cross-site top-level redirects (e.g. Stripe → success page)
        ];
        setcookie($this->di_config->cookie_name, $cookie, $cookie_options);
    }



    private function getIDandPHPHashedPasswordForUsername($username)
    {
        // get password hash
        $stmt = $this->di_pdo->prepare("SELECT `user_id`, `password_hash` FROM `users` WHERE LOWER(`username`) = LOWER(?) LIMIT 1");
        $stmt->execute([$username]);
        $result = $stmt->fetchAll();

        if (count($result) > 0) {
            return $result[0];
        } else {
            return [];
        }
    }
    /**
     * Looks up hashed password for username, and checks it against the password provided
     * @param $username
     * @param $password
     * @return bool
     */
    private function checkPHPHashedPassword($username, $password): int
    {
        // get password hash
        $user_id_and_hash_array = $this->getIDandPHPHashedPasswordForUsername($username);
        if (!empty($user_id_and_hash_array)) {
            $hashed_password = $user_id_and_hash_array['password_hash'];
            // check it
            if (password_verify($password, $hashed_password)) {
                // password is correct, so this user_id has logged in properly
                $user_id = $user_id_and_hash_array['user_id'];
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
    ): int
    {
        // Validate cookie and user agent match
        $stmt = $this->di_pdo->prepare("SELECT `user_id`, `aiu_id` FROM `cookies` WHERE `cookie` = ? AND `user_agent_md5` = ? LIMIT 1");
        $stmt->execute([$cookie, md5($user_agent)]);
        $result = $stmt->fetchAll();

        if(count($result) > 0)
        {
            $this->logged_in_aiu = isset($result[0]['aiu_id']) ? (int)$result[0]['aiu_id'] : null;
            return $result[0]['user_id'];
        }
        else
        {
            // Log why authentication failed
            $stmt2 = $this->di_pdo->prepare("SELECT `user_id` FROM `cookies` WHERE `cookie` = ? LIMIT 1");
            $stmt2->execute([$cookie]);
            $result2 = $stmt2->fetchAll();

            if(count($result2) > 0) {
                // Cookie exists but user agent doesn't match
                $user_id = $result2[0]['user_id'];
                $current_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                $this->logAuth("user_id: {$user_id}, IP: {$current_ip} - Cookie USER AGENT MISMATCH");
            } else {
                // Cookie doesn't exist at all
                $current_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                $this->logAuth("user_id: N/A, IP: {$current_ip} - Cookie NOT FOUND in database");
            }
            return 0;
        }
    }
    public function isLoggedIn(): bool
    {
        return $this->who_is_logged_in > 0;
    }

    public function loggedInID(): int
    {
        return $this->who_is_logged_in;
    }

    public function loggedInAIU(): ?int
    {
        return $this->logged_in_aiu;
    }


    public function logout(): void
    {
        $this->who_is_logged_in = 0;
        $this->killCookie();
        session_destroy();
        session_start();
        session_regenerate_id();
    }

    private function killCookie(): void
    {
        $cookie_options = [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => $this->di_config->domain_name,
            'secure' => true,   // HTTPS only
            'httponly' => true, // not accessible via JavaScript
            'samesite' => 'Strict' // None || Lax  || Strict
        ];
        setcookie($this->di_config->cookie_name, '', $cookie_options);
        $this->who_is_logged_in = 0;
        $this->logged_in_aiu = null;
    }

}

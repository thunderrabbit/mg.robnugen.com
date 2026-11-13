---
title: Registering a user goes through UserRepository
position: 79.0001
status: proven
whose_move: agent
check: mlaphp_register_via_user_repository
---
Chapter 8, ported. The new-DH-user-site template's one SQL call in a page
script is the `INSERT INTO users` in `wwwroot/login/register.php`. Rob chose
its new home once, as `\Database\UserRepository::create(string $username,
string $password_hash, string $role): void` in
`classes/Database/UserRepository.php`, so on every other site an agent makes
the same move without him. Any other query a site has in a page script stays
Rob's, in the next step.

The check wants `wwwroot/login/register.php` to say no `INSERT INTO users`
and `classes/Database/UserRepository.php` to define `create(`. A site with
no `wwwroot/login/register.php` passes: there is nothing to move.

## How to do it

1. Create these two files exactly as written. Commit them together
   (`Add Database\UserRepository::create()`), with the page untouched.
   The test uses in-memory SQLite: the row is stored, and a duplicate
   username throws a `PDOException` with code 23000.

   `classes/Database/UserRepository.php`:

       <?php

       declare(strict_types=1);

       namespace Database;

       /**
        * Persistence for accounts (the `users` table).
        *
        * SQL kept portable so the Unit suite can drive this on an in-memory SQLite.
        */
       class UserRepository
       {
           public function __construct(private readonly \PDO $di_pdo)
           {
           }

           /**
            * @throws \PDOException SQLSTATE 23000 when the username is taken.
            */
           public function create(string $username, string $password_hash, string $role): void
           {
               $stmt = $this->di_pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)");
               $stmt->execute([$username, $password_hash, $role]);
           }
       }

   `Tests/Unit/UserRepositoryTest.php`:

       <?php

       declare(strict_types=1);

       namespace Tests\Unit;

       use Codeception\Test\Unit;

       /**
        * In-memory SQLite, same reasoning as LoginThrottleTest: hermetic, no server.
        */
       class UserRepositoryTest extends Unit
       {
           private \PDO $pdo;

           protected function _before(): void
           {
               $this->pdo = new \PDO('sqlite::memory:');
               $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
               $this->pdo->exec(
                   "CREATE TABLE users (
                       user_id INTEGER PRIMARY KEY AUTOINCREMENT,
                       username TEXT NOT NULL UNIQUE,
                       password_hash TEXT NOT NULL,
                       role TEXT DEFAULT 'user',
                       created_at TEXT DEFAULT CURRENT_TIMESTAMP
                   )"
               );
           }

           public function testCreateStoresTheUser(): void
           {
               $repo = new \Database\UserRepository($this->pdo);
               $repo->create('rob', 'hash-a', 'admin');

               $stmt = $this->pdo->query("SELECT username, password_hash, role FROM users");
               $rows = $stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC);
               $this->assertSame(
                   [['username' => 'rob', 'password_hash' => 'hash-a', 'role' => 'admin']],
                   $rows
               );
           }

           /**
            * register.php turns SQLSTATE 23000 into "User already exists", so the
            * gateway must let that PDOException through untouched.
            */
           public function testDuplicateUsernameThrowsIntegrityViolation(): void
           {
               $repo = new \Database\UserRepository($this->pdo);
               $repo->create('rob', 'hash-a', 'admin');

               try {
                   $repo->create('rob', 'hash-b', 'user');
                   $this->fail('A duplicate username was accepted.');
               } catch (\PDOException $e) {
                   $this->assertSame('23000', (string) $e->getCode());
               }
           }
       }

2. In `wwwroot/login/register.php`, replace

       $stmt = $mla_database->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)");
       $stmt->execute([$username, $hash, $role]);

   with, on a site served on PHP 8.4 or later,

       new \Database\UserRepository($mla_database)->create($username, $hash, $role);

   or, on PHP 8.3 or earlier, which needs the parentheses,

       (new \Database\UserRepository($mla_database))->create($username, $hash, $role);

   Both pass the check. On 8.4 or later the first saves a Rector commit
   later (`NewMethodCallWithoutParenthesesRector`).

   Leave the surrounding try/catch (23000 becomes "User already exists")
   alone, and leave `password_hash()` in the page. Run the three standards
   gates, then commit (`Register users through UserRepository`) and run this
   step's check.

**Stop if the site differs.** If this site's INSERT has other columns, or
the page does more around it, do not improvise: leave the step open and
tell Rob. It then belongs to `sql_into_gateways`, which stays his for
whatever is left after the port.

Later groups Rob may want, not chosen yet and so not part of this step: the
three users SELECTs in `Auth\IsLoggedIn`, and splitting `PasswordRepository`
(its queries to `UserRepository`, its verify and change logic to `Auth`).
Those are in classes, not page scripts, so the check does not count them.

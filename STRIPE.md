# mg.robnugen.com integration plan (meisogambare + new-DH-user-site)

## Subscription + Pro capability

We will use **Stripe as the billing provider**. Stripe is the source of truth for paid subscription state; the app stores a local mirror for gating features and for debugging.

### What we store locally

1. Plan definitions (your business concepts): Free, Trial, Monthly, Annual, Lifetime
2. Stripe mappings (customer, subscription, price/product references)
3. Webhook event log (idempotency + audit)
4. Optional invoice ledger for support/reporting

---

### Table: `subscription_plans`

Stores the available plan types.

Columns:

* `subscription_plan_id` BIGINT UNSIGNED AUTO_INCREMENT
* `code` VARCHAR(32) NOT NULL UNIQUE

  * Suggested codes: `FREE`, `TRIAL`, `MONTHLY`, `ANNUAL`, `LIFETIME`
* `name` VARCHAR(64) NOT NULL
* `is_pro` TINYINT(1) NOT NULL DEFAULT 0
* `trial_days` SMALLINT UNSIGNED NULL
* `duration_days` SMALLINT UNSIGNED NULL
* `created_at_utc` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
* `updated_at_utc` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)

Stripe mapping (nullable for FREE / manual lifetime):

* `stripe_product_ref` VARCHAR(64) NULL
* `stripe_price_ref` VARCHAR(64) NULL
* `stripe_livemode` TINYINT(1) NOT NULL DEFAULT 0

Notes:

* You can keep pricing entirely in Stripe; no need to store cents/currency locally unless you want.

---

### Table: `stripe_customers`

Maps users to Stripe customers.

Columns:

* `stripe_customer_id` BIGINT UNSIGNED AUTO_INCREMENT
* `user_id` BIGINT UNSIGNED NOT NULL
* `stripe_customer_ref` VARCHAR(64) NOT NULL
* `livemode` TINYINT(1) NOT NULL
* `created_at_utc` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)

Indexes/constraints:

* UNIQUE (`stripe_customer_ref`)
* UNIQUE (`user_id`, `livemode`)

---

### Table: `stripe_subscriptions`

Local mirror of Stripe subscription state for feature gating.

Columns:

* `stripe_subscription_id` BIGINT UNSIGNED AUTO_INCREMENT
* `user_id` BIGINT UNSIGNED NOT NULL
* `subscription_plan_id` BIGINT UNSIGNED NOT NULL

Stripe refs:

* `stripe_subscription_ref` VARCHAR(64) NOT NULL
* `stripe_customer_ref` VARCHAR(64) NOT NULL
* `stripe_price_ref` VARCHAR(64) NULL

Status:

* `status` VARCHAR(32) NOT NULL

  * Store the Stripe status string (e.g., `trialing`, `active`, `past_due`, `canceled`, `incomplete`, `unpaid`)
* `cancel_at_period_end` TINYINT(1) NOT NULL DEFAULT 0

Periods (UTC is fine here because it comes from Stripe):

* `current_period_start_utc` DATETIME(6) NULL
* `current_period_end_utc` DATETIME(6) NULL
* `canceled_at_utc` DATETIME(6) NULL
* `ended_at_utc` DATETIME(6) NULL

Env + timestamps:

* `livemode` TINYINT(1) NOT NULL
* `created_at_utc` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
* `updated_at_utc` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)

Indexes/constraints:

* UNIQUE (`stripe_subscription_ref`)
* INDEX (`user_id`, `status`)
* INDEX (`user_id`, `current_period_end_utc`)

---

### Table: `stripe_webhook_events`

Stores Stripe events for idempotency and debugging.

Columns:

* `stripe_event_id` VARCHAR(64) NOT NULL
* `type` VARCHAR(128) NOT NULL
* `livemode` TINYINT(1) NOT NULL
* `received_at_utc` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
* `payload_json` JSON NOT NULL
* `processed_at_utc` DATETIME(6) NULL
* `process_status` VARCHAR(16) NOT NULL DEFAULT 'new'  -- new/ok/error
* `error_message` TEXT NULL

Constraints:

* PRIMARY KEY (`stripe_event_id`)

---

### Optional table: `stripe_invoices`

Useful for support (“what did I pay?”). Not required for gating.

Columns:

* `stripe_invoice_ref` VARCHAR(64) NOT NULL
* `user_id` BIGINT UNSIGNED NOT NULL
* `stripe_subscription_ref` VARCHAR(64) NULL
* `status` VARCHAR(32) NULL
* `amount_due` BIGINT NULL
* `amount_paid` BIGINT NULL
* `currency` CHAR(3) NULL
* `stripe_hosted_invoice_url` TEXT NULL
* `invoice_pdf_url` TEXT NULL
* `created_utc` DATETIME(6) NULL
* `paid_utc` DATETIME(6) NULL
* `livemode` TINYINT(1) NOT NULL

Constraints:

* PRIMARY KEY (`stripe_invoice_ref`)

---

### Pro gating rule (Stripe-backed)

A user is considered **Pro** if they have:

* an active Stripe subscription row whose `status` is in (`trialing`, `active`)

  * optionally treat `past_due` as Pro during a grace window if you want
* and `current_period_end_utc` is NULL or in the future
* and the linked `subscription_plans.is_pro = 1`

Lifetime:

* Can be implemented as either:

  1. A Stripe one-time purchase + you create a local “entitlement” record, OR
  2. A manual admin grant (no Stripe)

---

### SQL (drop-in example)

```sql
CREATE TABLE subscription_plans (
  subscription_plan_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(32) NOT NULL,
  name VARCHAR(64) NOT NULL,
  is_pro TINYINT(1) NOT NULL DEFAULT 0,
  trial_days SMALLINT UNSIGNED NULL,
  duration_days SMALLINT UNSIGNED NULL,
  stripe_product_ref VARCHAR(64) NULL,
  stripe_price_ref VARCHAR(64) NULL,
  stripe_livemode TINYINT(1) NOT NULL DEFAULT 0,
  created_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (subscription_plan_id),
  UNIQUE KEY uq_plan_code (code)
) ENGINE=InnoDB;

CREATE TABLE stripe_customers (
  stripe_customer_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  stripe_customer_ref VARCHAR(64) NOT NULL,
  livemode TINYINT(1) NOT NULL,
  created_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (stripe_customer_id),
  UNIQUE KEY uq_stripe_customer_ref (stripe_customer_ref),
  UNIQUE KEY uq_user_livemode (user_id, livemode),
  CONSTRAINT fk_stripe_customers_user
    FOREIGN KEY (user_id) REFERENCES users(user_id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE stripe_subscriptions (
  stripe_subscription_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  subscription_plan_id BIGINT UNSIGNED NOT NULL,

  stripe_subscription_ref VARCHAR(64) NOT NULL,
  stripe_customer_ref VARCHAR(64) NOT NULL,
  stripe_price_ref VARCHAR(64) NULL,

  status VARCHAR(32) NOT NULL,
  cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,

  current_period_start_utc DATETIME(6) NULL,
  current_period_end_utc DATETIME(6) NULL,
  canceled_at_utc DATETIME(6) NULL,
  ended_at_utc DATETIME(6) NULL,

  livemode TINYINT(1) NOT NULL,
  created_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

  PRIMARY KEY (stripe_subscription_id),
  UNIQUE KEY uq_stripe_subscription_ref (stripe_subscription_ref),
  KEY idx_user_status (user_id, status),
  KEY idx_user_period_end (user_id, current_period_end_utc),
  KEY idx_plan (subscription_plan_id),

  CONSTRAINT fk_stripe_subscriptions_user
    FOREIGN KEY (user_id) REFERENCES users(user_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_stripe_subscriptions_plan
    FOREIGN KEY (subscription_plan_id) REFERENCES subscription_plans(subscription_plan_id)
    ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE stripe_webhook_events (
  stripe_event_id VARCHAR(64) NOT NULL,
  type VARCHAR(128) NOT NULL,
  livemode TINYINT(1) NOT NULL,
  received_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  payload_json JSON NOT NULL,
  processed_at_utc DATETIME(6) NULL,
  process_status VARCHAR(16) NOT NULL DEFAULT 'new',
  error_message TEXT NULL,
  PRIMARY KEY (stripe_event_id)
) ENGINE=InnoDB;

CREATE TABLE stripe_invoices (
  stripe_invoice_ref VARCHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  stripe_subscription_ref VARCHAR(64) NULL,
  status VARCHAR(32) NULL,
  amount_due BIGINT NULL,
  amount_paid BIGINT NULL,
  currency CHAR(3) NULL,
  stripe_hosted_invoice_url TEXT NULL,
  invoice_pdf_url TEXT NULL,
  created_utc DATETIME(6) NULL,
  paid_utc DATETIME(6) NULL,
  livemode TINYINT(1) NOT NULL,
  PRIMARY KEY (stripe_invoice_ref),
  KEY idx_user_invoice (user_id, created_utc),
  CONSTRAINT fk_stripe_invoices_user
    FOREIGN KEY (user_id) REFERENCES users(user_id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO subscription_plans (code, name, is_pro, trial_days, duration_days)
VALUES
  ('FREE', 'Free', 0, NULL, NULL),
  ('TRIAL', 'Free Trial', 1, 14, 14),
  ('MONTHLY', 'Monthly', 1, NULL, 30),
  ('ANNUAL', 'Annual', 1, NULL, 365),
  ('LIFETIME', 'Lifetime', 1, NULL, NULL);
```


At this point:

* Our repo history contains **all** DH scaffold commits **and** all meisogambare commits.
* The old static timer lives at `/mg/` under the DreamHost webroot.

---

### 3) Clock accessibility and landing page

**Anonymous users:**

* Can access the meditation clock at `/mg/` without logging in
* The clock works fully for anonymous users, but sessions are not saved

**Root page (`/`) behavior:**

* **Anonymous users:** See a landing page with:
  * Explainer of benefits of logging in (e.g., "Save your meditation durations")
  * Link to `/mg/` to use the clock immediately
* **Logged-in users:** Automatically redirected to `/mg/` (unless a GET param keeps them on `/` - TBD for future)

Plan (minimal-change, fast path):

* The existing static clock at `/mg/` remains accessible to everyone
* Create a PHP controller at `wwwroot/index.php` that:
  * Checks login status
  * If logged in: redirects to `/mg/`
  * If not logged in: renders `templates/landing/index.tpl.php` (landing page with explainer + link)
* The clock at `/mg/` will need to detect login status to enable save functionality

---

## 3) Web roots + public URL structure

### Web root reality check

* **`new-DH-user-site` serves public files from `wwwroot/`** (that folder is explicitly described as “Public-facing files”).
* DreamHost setup in that repo expects the domain’s web directory to point at a path ending in `/wwwroot`.

So for `mg.robnugen.com` on DreamHost, plan on:

* Repo checkout at: `/home/<dh_user>/mg.robnugen.com/`
* **Domain web directory** set to: `/home/<dh_user>/mg.robnugen.com/wwwroot`

### Desired behavior for `mg.robnugen.com/`

**Root page (`/`):**

* If user is **not logged in**: show landing page with explainer and link to `/mg/`
* If user is **logged in**: redirect to `/mg/` (unless GET param overrides - TBD)

**Clock page (`/mg/`):**

* Accessible to **everyone** (anonymous and logged-in users)
* Anonymous users: clock works, but sessions are not saved
* Logged-in users: clock works AND sessions are saved to database
* Pro users: get timer continuity across navigation (session persists when navigating away)

### Implementation sketch (fits the DH scaffold)

1. `wwwroot/index.php` (root landing page):
   * Check if user is logged in (via DB-backed session)
   * If logged in: redirect to `/mg/`
   * If not logged in: render `templates/landing/index.tpl.php`

2. `wwwroot/mg/index.php` (or existing static page, enhanced):
   * Check login status (optional, for save functionality)
   * Render the clock UI for everyone
   * If logged in: enable "Save Session" functionality
   * If Pro: enable timer continuity (load active session on page load)

3. `wwwroot/login.php` renders the login form and, on success:
   * sets the cookie/session record
   * redirects to `$_GET['next'] ?? '/mg/'`

### Where the code should live

Since the web root is `wwwroot/`, the structure is:

* `wwwroot/index.php` = PHP controller for the landing page (redirects if logged in)
* `templates/landing/index.tpl.php` = landing page template (explainer + link to `/mg/`)
* `wwwroot/mg/` = meditation clock (existing static files, enhanced with save functionality)
* Static assets remain at:
  * `/mg/css/...`
  * `/mg/javascript/...`
  * `/mg/assets/...`

---

## 4) README lineage note (so it “feels like a fork”)

Add to top-level `README.md`:

* This repo is derived from `thunderrabbit/mg` (history preserved via mirror push)
* `thunderrabbit/new-DH-user-site` is incorporated under `/dh` via git subtree (history preserved)

Example snippet:

```md
## Lineage
This repository is derived from `thunderrabbit/mg` (history preserved via mirror push).

It incorporates `thunderrabbit/new-DH-user-site` under `/dh` via `git subtree` (history preserved).
```

---

## 5) Database design for meditation sessions (Kai)

Naming: This is an **activity instance**, so the table is named `activity_kai`.

You want:

* Duration stored as **BIGINT milliseconds** (machine-friendly)
* Timestamps that **include timezone context** so you can later analyze “time-of-day patterns” correctly even as you travel

### Core strategy (updated per preference)

You prefer **local-time-first storage**, for human verifiability and sanity checks. That makes sense, especially since you’ll be eyeballing DB rows against what the UI shows.

**Updated approach:**

1. Store **local timestamps directly** in the database (the clock time you experienced).
2. Also store the **timezone context at that moment**, so local time remains interpretable and analyzable later.
3. Store **duration in ms** independently, so math never depends on timestamp subtraction.

In practice:

* You will *see the same times* in the DB that you see on the website.
* Duration math stays trivial and robust.
* Cross‑timezone or DST edge cases remain solvable because timezone context is preserved.

This gives you:

* Accurate duration math (`end_ms - start_ms` if no pauses; otherwise `duration_ms`)
* Correct “local hour” and “local date” analysis even if DST changes or you change timezones

---

### Table: `activity_kai`

Purpose: one row per meditation instance (a “Kai”).

**Primary key:** `activity_kai_id`

**Foreign key:** `user_id` → `users.user_id`

**Foreign key:** `activity_id` → (define an `activities` table, or treat `activity_id` as an application-defined identifier if you don’t want a lookup table yet)

Suggested columns (local-time-first):

* `activity_kai_id` BIGINT UNSIGNED AUTO_INCREMENT
* `user_id` BIGINT UNSIGNED NOT NULL

Activity identity (for multiple concurrent timers):

* `activity_id` BIGINT UNSIGNED NOT NULL

  * What “kind” of timer this is (e.g., breath, mantra, body scan). A Pro user can run multiple activities concurrently.

Local times (what the user actually experienced):

* `start_local_dt` DATETIME(6) NOT NULL
* `end_local_dt` DATETIME(6) NULL

Durations:

* `duration_ms` BIGINT NULL

  * Canonical source of truth for elapsed meditation time

Timezone context at the moment of start:

* `start_tz` VARCHAR(64) NOT NULL

  * IANA timezone name (e.g., `Asia/Tokyo`, `Australia/Adelaide`)
* `start_utc_offset_min` SMALLINT NOT NULL

  * Snapshot offset at start (e.g., +540)

(Optional, rarely needed):

* `end_tz` VARCHAR(64) NULL
* `end_utc_offset_min` SMALLINT NULL

Metadata:

* `created_at_local_dt` DATETIME(6) NOT NULL
* `updated_at_local_dt` DATETIME(6) NOT NULL

Indexes:

* `INDEX idx_user_start (user_id, start_utc_ms)`
* `INDEX idx_user_end (user_id, end_utc_ms)`
* `INDEX idx_user_tz_start (user_id, start_tz, start_utc_ms)`

---

### Pauses (paid feature, not recorded in detail)

You don’t want pause events stored, and you plan to make “pause” a **Pro** feature.

So for the free version:

* No pause button
* No pause table
* `duration_ms` is simply computed from wall-clock elapsed time

For Pro users (initially simple, no detail):

* You may allow a pause/resume UI
* Still **no pause history table**
* You can store pause-adjusted total in `duration_ms` when the session ends (or as it updates)

(If you ever want to audit pauses later, you can add a separate table, but it’s intentionally out of scope for v1.)

---

### SQL schema (drop-in example)

````sql
CREATE TABLE meditation_kai (
  meditation_kai_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,

  activity_id BIGINT UNSIGNED NOT NULL,

  start_local_dt DATETIME(6) NOT NULL,
  end_local_dt DATETIME(6) NULL,

  duration_ms BIGINT NULL,

  start_tz VARCHAR(64) NOT NULL,
  start_utc_offset_min SMALLINT NOT NULL,

  end_tz VARCHAR(64) NULL,
  end_utc_offset_min SMALLINT NULL,

  created_at_local_dt DATETIME(6) NOT NULL,
  updated_at_local_dt DATETIME(6) NOT NULL,

  PRIMARY KEY (meditation_kai_id),
  KEY idx_user_start (user_id, start_local_dt),
  KEY idx_user_end (user_id, end_local_dt),
  KEY idx_user_activity_start (user_id, activity_id, start_local_dt),

  CONSTRAINT fk_meditation_kai_user
    FOREIGN KEY (user_id) REFERENCES users(user_id)
    ON DELETE CASCADE
) ENGINE=InnoDB;
```sql
CREATE TABLE meditation_kai (
  meditation_kai_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,

  start_local_dt DATETIME(6) NOT NULL,
  end_local_dt DATETIME(6) NULL,

  duration_ms BIGINT NULL,

  start_tz VARCHAR(64) NOT NULL,
  start_utc_offset_min SMALLINT NOT NULL,

  end_tz VARCHAR(64) NULL,
  end_utc_offset_min SMALLINT NULL,

  created_at_local_dt DATETIME(6) NOT NULL,
  updated_at_local_dt DATETIME(6) NOT NULL,

  PRIMARY KEY (meditation_kai_id),
  KEY idx_user_start (user_id, start_local_dt),
  KEY idx_user_end (user_id, end_local_dt),
  KEY idx_user_tz_start (user_id, start_tz, start_local_dt),

  CONSTRAINT fk_meditation_kai_user
    FOREIGN KEY (user_id) REFERENCES users(user_id)
    ON DELETE CASCADE
) ENGINE=InnoDB;
```sql
CREATE TABLE meditation_kai (
  meditation_kai_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,

  start_utc_ms BIGINT NOT NULL,
  end_utc_ms BIGINT NULL,

  duration_ms BIGINT NULL,

  start_tz VARCHAR(64) NOT NULL,
  start_utc_offset_min SMALLINT NULL,

  end_tz VARCHAR(64) NULL,
  end_utc_offset_min SMALLINT NULL,

  created_at_utc_ms BIGINT NOT NULL,
  updated_at_utc_ms BIGINT NOT NULL,

  PRIMARY KEY (meditation_kai_id),
  KEY idx_user_start (user_id, start_utc_ms),
  KEY idx_user_end (user_id, end_utc_ms),
  KEY idx_user_tz_start (user_id, start_tz, start_utc_ms),

  CONSTRAINT fk_meditation_kai_user
    FOREIGN KEY (user_id) REFERENCES users(user_id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE meditation_kai_pauses (
  activity_kai_pause_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  meditation_kai_id BIGINT UNSIGNED NOT NULL,

  pausestart_utc_ms BIGINT NOT NULL,
  pauseend_utc_ms BIGINT NULL,

  pause_tz VARCHAR(64) NULL,
  pause_utc_offset_min SMALLINT NULL,

  PRIMARY KEY (meditation_kai_pause_id),
  KEY idx_kai_pause (meditation_kai_id, pausestart_utc_ms),

  CONSTRAINT fk_meditation_kai_pauses_kai
    FOREIGN KEY (meditation_kai_id) REFERENCES meditation_kai(meditation_kai_id)
    ON DELETE CASCADE
) ENGINE=InnoDB;
````

---

### User story: timer continuity across navigation (Pro feature)

**Feature:** Timer continuity is available to **Pro users only** (any paid level: `TRIAL`, `MONTHLY`, `ANNUAL`, `LIFETIME`).

**Scenario:**

* User starts a meditation
* User navigates away
* User returns later to the clock page
* User is logged in and **Pro** (subscription status is `trialing` or `active`)

**Behavior:**

* The elapsed time should reflect real wall-clock time since start, as if the page had stayed open.

**Non-Pro logged-in users:**

* Can save completed meditation sessions
* Do NOT get timer continuity (timer resets if they navigate away)

**Implementation sketch:**

* On “Start”, create a `meditation_kai` row with `start_local_dt` (and tz context).
* Store the `meditation_kai_id` as the user’s “active session” (e.g., in DB on the user row, or a small `user_state` table, or in the session cookie DB).
* When the clock page loads:

  * If there is an active `meditation_kai` for the user, compute elapsed as:

    * `now_local_dt - start_local_dt` (since same tz 99.9% of the time)
  * Render the timer with that value.
* On “Finish”, write:

  * `end_local_dt = now`
  * `duration_ms = elapsed_ms`

**Note:** Even if the user’s timezone changed while away, you can still compute elapsed via offsets (using stored `start_utc_offset_min` plus current offset), but you said 99.9% same tz, so keep v1 simple.

---

### How to do “durations vs local start time” analysis

For each session row:

* Use `start_local_dt` directly (already the local clock time)
* Bucket by local hour (0–23) or local day-of-week
* Plot duration distributions per bucket

Timezone fields remain useful for:

* Displaying “(UTC+09:00, Asia/Tokyo)”
* Handling rare edge cases (travel/DST) if you ever choose to

---

## Notes / pitfalls

* If you use GitHub Pages, confirm which branch is served (often `gh-pages`).
* If you later make the DH scaffold the root site, plan that as a **dedicated migration commit series** (move files, adjust paths, keep redirects).
* Subtree without `--squash` keeps full commit history, but your main repo history will include both projects’ commits.
* For the `/login?next=...` flow, always validate/whitelist `next` to prevent open-redirect issues (e.g., only allow paths starting with `/`).

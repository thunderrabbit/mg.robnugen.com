# Monitization Proposal

> “Does it cost the agent fewer tokens / less compute to call my API than to recreate this capability internally?”

That is a **much smarter framing** than traditional SaaS pricing.

Below is a fully rewritten proposal incorporating **token-cost-based pricing strategy**.

---

## Proposal

### Transforming mg.robnugen.com into an AI-Optimized Session Ledger API

### With Token-Cost-Based Pricing Strategy

---

# 1. Executive Summary

This proposal evaluates whether **mg.robnugen.com** (currently a meditation/session tracking site) can become:

1. A lightweight, persistent backend for AI agents
2. A monetized API product
3. Priced based on *token cost savings* vs local recreation

Instead of charging arbitrarily, we will:

> Price the API below the cost (in tokens + compute + engineering complexity) required for an agent to reproduce the same functionality internally.

The goal is to make integration economically rational for AI agents.

---

# 2. Core Insight: Agents Optimize for Token & Compute Efficiency

An AI agent deciding whether to:

A) Call your API
B) Recreate the logic internally

Will consider:

* Prompt tokens required
* Reasoning tokens required
* Storage strategy
* Memory complexity
* Error handling
* Time cost
* Engineering complexity

If your API:

* Reduces tokens
* Reduces reasoning complexity
* Reduces state management
* Reduces failure modes

Then it becomes economically attractive.

There is also a second-order benefit: **state that survives model swaps.**

An agent's internal memory is tied to its context window and model version. When models are upgraded, swapped, or replaced, internal state evaporates. A hosted ledger persists across all of those transitions — the user's history is always there regardless of which model or tool is calling.

---

# 3. Why mg.robnugen.com Has Potential

The site already implements:

* User accounts
* Activity types
* Session logging
* Persistent storage
* Historical retrieval
* Timer-based session recording

This means you already have:

> A persistent, structured behavioral ledger.

That is the key asset.

Not the timer.
Not the UI.
The ledger.

There is a second asset: **a multi-agent hub.**

A single user could have a sleep-tracking agent, a workout agent, and a meditation agent — all writing to the same session history. None of those agents needs to know about the others. mg becomes the shared truth layer. That is harder to replicate than a timer and more defensible than yet another to-do API.

---

# 4. Agent Decision Model: Recreate vs Integrate

Let’s quantify.

If an agent wants to log sessions for a user, it must:

### To Recreate Locally:

1. Define schema
2. Manage storage (external DB or file store)
3. Handle concurrency
4. Maintain authentication
5. Write retrieval queries
6. Compute streaks
7. Store history
8. Handle failures

This requires:

* Repeated prompt engineering
* State serialization
* Tool management
* Possibly external hosting

Token cost estimate per session lifecycle:

* 1,000–5,000 tokens per meaningful interaction
* Plus system memory overhead

At scale:

* Tens of thousands of tokens/month per user

That costs real money.

---

# 5. Token Cost Comparison Model

We introduce:

## Token Cost Equivalent Pricing (TCEP)

We calculate:

**Cost to recreate per 1,000 session operations**

Example:

If recreating session management internally costs:

* 3,000 tokens per 10 session interactions
* Model cost approx $0.01–$0.06 per 1k tokens (varies by model)

Then 1,000 sessions recreated:

* 300,000 tokens
* $3–$18 model cost
* Plus engineering overhead

If mg API handles 1,000 sessions for:

$2–$5

You are cheaper than local recreation.

That is the pricing anchor.

---

# 6. Proposed API Structure (Ledger-Focused)

Instead of generic “tasks,” position this as:

## Behavioral Session Ledger API

Endpoints:

* `POST /api/v1/sessions`
* `GET /api/v1/sessions?from=YYYY-MM-DD&to=YYYY-MM-DD&activity_id=N&limit=50&offset=0`
* `GET /api/v1/sessions/{ak_id}`
* `PATCH /api/v1/sessions/{ak_id}/stop`
* `DELETE /api/v1/sessions/{ak_id}`
* `GET /api/v1/stats`
* `POST /api/v1/activities`
* `GET /api/v1/activities`

The API does:

* Persistent session storage
* Aggregation
* Streak calculation
* Summaries
* Date filtering
* Activity filtering

This reduces reasoning load for agents.

## Concrete Agent Workflows

These are the "jobs to be done" that justify integration over local recreation:

1. **"Start a 12-minute meditation timer and log it."**
   Agent calls `POST /api/v1/sessions` with `activity_id=1, intended_sec=720`. Done. No schema, no DB, no state to carry in context.

2. **"Summarize my last 14 days of sleep sessions."**
   Agent calls `GET /api/v1/sessions?from=2026-02-10&activity_id=2` and receives a structured list. No raw SQL, no serialized history in the prompt.

3. **"Detect habit streaks and create nudges."**
   Agent calls `GET /api/v1/stats`. Streak is pre-computed server-side. Zero reasoning tokens spent on calendar arithmetic.

4. **"Log workouts from three different coaching agents into one history."**
   Each agent authenticates with the same user's API key and posts sessions. The ledger merges them transparently. No cross-agent coordination needed.

These workflows are impossible to replicate cheaply in a model's context window. That is the sell.

---

# 7. Pricing Strategy Based on Token Savings

We price based on:

### 1️⃣ Storage Offload Savings

Agent does not need to:

* Persist state internally
* Manage DB infrastructure
* Serialize large session histories

### 2️⃣ Reasoning Reduction

Agent does not need to:

* Recalculate streaks repeatedly
* Query and summarize raw data every time

### 3️⃣ Engineering Simplification

Agent developers integrate 5 endpoints instead of building a backend.

---

# 8. Pricing Tiers (Token-Indexed)

### Developer Tier

$5/month
Up to 5,000 session events

Equivalent token recreation cost:
~$10–$40 in model usage

You’re cheaper.

---

### Growth Tier

$15/month
Up to 25,000 session events

Equivalent token recreation cost:
~$50–$200

Again cheaper.

---

### Enterprise / Agent Fleet

Custom pricing
High-volume persistent behavioral data

---

# 9. Shared Hosting Feasibility

All feasible on DreamHost:

* PHP API middleware
* MySQL storage
* API key authentication
* Stripe billing
* Credit-based usage enforcement
* Basic rate limiting
* 402 responses when limits exceeded

No blockchain required.
No Redis required (at MVP stage).
No heavy infrastructure.

---

# 10. Technical Implementation Plan

> **Codebase review complete.** The ledger already exists. The gaps are: API key auth, usage tracking, and billing.

## What Already Exists (No Work Needed)

The core ledger is fully built:

| Concept | Already Exists |
|---------|---------------|
| Session storage | `activity_kai` table — `ak_id`, `user_id`, `actual_sec` (NULL = active), `intended_sec`, `start_local_dt` |
| Activity types | `activities` table — FREE / PUBLIC / PRIVATE enum |
| Public session sharing | `activity_session_keys` — 11-char YouTube-style keys |
| Session CRUD endpoints | `/api/start-activity.php`, `/api/stop-activity.php`, `/api/list-completed-sessions.php`, `/api/get-session.php` |
| JSON API pattern | All existing endpoints return JSON, use PDO prepared statements |
| Role-based access | `users.role` column: `'user'`, `'paid'`, `'admin'` |
| DB migration system | `db_schemas/NN_name/` directories; tracked in `applied_DB_versions` |

The PHP class that wraps the ledger is `ActivityTracking\ActivityKai`, with methods:
`startActivity()`, `stopActivity()`, `getUserSessions()`, `verifyOwnership()`

---

## Phase 1 — API Key Authentication

**The biggest gap: all existing endpoints require cookie auth. External agents cannot use cookies.**

### New migration: `db_schemas/07_api_keys/`

```sql
-- create_api_keys.sql
CREATE TABLE api_keys (
    key_id      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    api_key     CHAR(64) NOT NULL UNIQUE,
    label       VARCHAR(255) NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_used   DATETIME NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);
```

### New class: `classes/Auth/ApiKey.php`

Follows the existing `Auth\IsLoggedIn` pattern:

```php
class ApiKey {
    public function validateKey(string $raw_key): ?int  // returns user_id or null
    public function generateKey(int $user_id, string $label): string
    public function revokeKey(int $key_id, int $user_id): bool
}
```

### Middleware pattern (reuse in every v1 endpoint)

```php
// At top of each /api/v1/*.php, after prepend.php
$auth_user_id = null;
$raw_key = $_SERVER['HTTP_X_API_KEY'] ?? null;
if ($raw_key) {
    $apiKeyAuth = new Auth\ApiKey($pdo);
    $auth_user_id = $apiKeyAuth->validateKey($raw_key);
}
if (!$auth_user_id) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or missing API key']);
    exit;
}
```

**Deliverable:** Agents can authenticate with `X-API-Key: sk_...` header.

---

## Phase 2 — v1 API Endpoints

Create `wwwroot/api/v1/` directory. Each endpoint wraps existing `ActivityKai` and `Activity` class methods — **no new DB logic needed**.

| New Endpoint | Wraps | Notes |
|---|---|---|
| `GET /api/v1/sessions` | `ActivityKai::getUserSessions()` | Add `limit`, `offset`, date filter params |
| `POST /api/v1/sessions` | `ActivityKai::startActivity()` | Requires `activity_id`, `intended_sec`, `timezone` |
| `PATCH /api/v1/sessions/{ak_id}/stop` | `ActivityKai::stopActivity()` | Requires `actual_sec` |
| `GET /api/v1/sessions/{ak_id}` | `ActivityKai::getUserSessions()` filtered | Ownership via `verifyOwnership()` |
| `GET /api/v1/activities` | `Activity::getActivitiesForUser()` | Returns available activity types |
| `POST /api/v1/activities` | `Activity::createUserActivity()` | Creates PRIVATE activity |
| `GET /api/v1/stats` | Direct SQL on `activity_kai` | Streak, total time, session count |

`/api/v1/stats` is the highest-value endpoint for agents — it offloads streak and aggregate computation entirely.

**Deliverable:** Working v1 endpoints usable by any HTTP client, plus an OpenAPI spec (`wwwroot/api/v1/openapi.yaml`) so agents and developer tools can discover and validate the API without reading documentation. This is the lowest-effort step that most increases agent integration speed.

---

## Phase 3 — Usage Tracking and Credit Enforcement

### New migration: `db_schemas/08_api_usage/`

```sql
-- create_api_usage.sql
CREATE TABLE api_usage (
    usage_id    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    key_id      BIGINT UNSIGNED NOT NULL,
    endpoint    VARCHAR(128) NOT NULL,
    credited    TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id),
    FOREIGN KEY (key_id) REFERENCES api_keys(key_id)
);

-- create_api_credits.sql
CREATE TABLE api_credits (
    credit_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL UNIQUE,
    credits_remaining INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);
```

### Credit check added to middleware (after Phase 1 auth):

```php
$credits = $pdo->query(“SELECT credits_remaining FROM api_credits WHERE user_id = $auth_user_id”)->fetchColumn();
if ($credits <= 0) {
    http_response_code(402);
    echo json_encode(['error' => 'No credits remaining', 'upgrade_url' => 'https://mg.robnugen.com/billing']);
    exit;
}
// Deduct after successful response
```

**Deliverable:** Usage metered; 402 returned when exhausted.

---

## Phase 4 — Stripe Integration

The existing `users.role` column (`'user'`, `'paid'`, `'admin'`) already provides the access tier hook. Stripe only needs to flip `role` to `'paid'` and top up `api_credits`.

### New files needed:

| File | Purpose |
|------|---------|
| `wwwroot/billing/index.php` | Pricing page with Stripe Checkout links |
| `wwwroot/billing/webhook.php` | Receives Stripe events |
| `wwwroot/billing/success.php` | Post-payment confirmation |
| `classes/Billing/StripeWebhook.php` | Parses events, updates `users.role` + `api_credits` |

### Webhook logic (in `StripeWebhook.php`):

```php
// On checkout.session.completed:
$pdo->execute(“UPDATE users SET role = 'paid' WHERE user_id = ?”, [$user_id]);
$pdo->execute(“INSERT INTO api_credits (user_id, credits_remaining) VALUES (?, ?)
               ON DUPLICATE KEY UPDATE credits_remaining = credits_remaining + ?”,
               [$user_id, $credits, $credits]);
```

No subscription tracking table needed at MVP — Stripe handles renewal; webhook tops up credits on each payment.

### Config additions to `classes/Config.php`:

```php
public $stripe_secret_key     = 'sk_live_...';
public $stripe_webhook_secret = 'whsec_...';
public $stripe_price_developer = 'price_...';  // $5/mo → 5,000 credits
public $stripe_price_growth    = 'price_...';  // $15/mo → 25,000 credits
```

**Deliverable:** Paying users get credits; free users get 0 credits (or a small trial allotment).

---

# 11. Income Reality Assessment

Let’s be sober.

This will not:

* Instantly generate large revenue
* Attract OpenAI-scale clients

But it could:

* Generate $100–$1,000/month if positioned well
* Serve as proof-of-concept
* Become a reusable monetization pattern

The real asset isn’t mg.

It’s the infrastructure pattern you’ll learn.

---

# 12. Go / No-Go Criteria

We proceed only if:

* API extraction is simple (no major refactor)
* Ledger abstraction is clean
* Hosting performance is acceptable
* Stripe integration is straightforward
* Token savings narrative is credible

If not:
We classify as learning experiment.

---

# 13. Strategic Outcome

Best Case:
You create a low-cost behavioral ledger service optimized for AI integration.

Worst Case:
You gain direct experience building:

* Metered API
* Token-based pricing logic
* Agent-optimized backend
* Monetization middleware

Which is valuable skill capital.

---

# Final Thought

The key is not:

“Will AI pay for my to-do list?”

The key is:

“Can I make this cheaper and simpler than AI agents doing it themselves?”

That is the only pricing strategy that makes sense in an agent-native world.

---

Next possible steps:

1. Help calculate concrete token-equivalent pricing numbers
2. Simulate realistic usage scenarios
3. Or pressure-test whether mg truly has enough differentiation to justify even token-based pricing

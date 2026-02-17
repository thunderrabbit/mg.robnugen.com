# Second Brain Integration Proposal

**Date:** 2026-02-17
**Author:** Claude (Opus 4.5)
**Status:** Draft

## Overview

This proposal outlines how the existing DreamHost PHP site can participate in a "Second Brain" system, integrating with the existing TODO functionality rather than operating as a standalone component.

## The Second Brain Workflow

The system implements a four-part inner loop:

1. **Capture to Slack** - Frictionless thought capture via private Slack channel
2. **File to Database** - AI-powered classification and storage
3. **Daily Digest** - Morning summary with auto-generated todos
4. **Weekly Review** - Weekly summary of patterns and open loops

## Architecture

```
Slack (Capture)
     │
     ▼
┌─────────────────────────────────────┐
│  PHP Webhook Endpoint               │
│  /api/second-brain/capture.php      │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│  Claude API Classification          │
│  - Extract entities (people, etc.)  │
│  - Categorize (project/idea/admin)  │
│  - Identify actionable items        │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│  Second Brain Tables                │
│  - sb_inbox (raw capture log)       │
│  - sb_people                        │
│  - sb_projects                      │
│  - sb_ideas                         │
│  - sb_admin                         │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│  Daily Digest Cron (morning)        │
│  1. Query Second Brain tables       │
│  2. Claude: generate top actions    │
│  3. Todo::createTodo() for each     │  ◄── KEY INTEGRATION
│  4. Post summary to Slack DMs       │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│  Existing TODO System               │
│  /todos/index.php                   │
│  (AI-generated todos appear here)   │
└─────────────────────────────────────┘
```

## Integration with Existing System

### What Gets Reused

| Component | How It's Used |
|-----------|---------------|
| `prepend.php` | Bootstrap for new endpoints |
| `Database\Base` | PDO connection layer |
| `DBExistaroo` | Migration system for new tables |
| `Auth\IsLoggedIn` | Protect API endpoints |
| `ActivityTracking\Todo` | **Create todos from digest** |
| `Template.php` | Admin UI for Second Brain |
| `/api/todos/create_batch.php` | Potential reuse for bulk todo creation |

### The Key Integration Point

The daily digest cron doesn't just post to Slack - it **creates real todos** using the existing `Todo::createTodo()` method:

```php
// In cron/daily_digest.php
$todoHelper = new \ActivityTracking\Todo($pdo);

foreach ($aiGeneratedActions as $action) {
    $todoHelper->createTodo([
        'user_id' => $user_id,
        'title' => $action['title'],
        'description' => 'Generated from Second Brain: ' . $action['source'],
        'due_date' => date('Y-m-d'), // Today
    ]);
}
```

This means captured thoughts become actionable items in your existing workflow.

## New Components Required

### Database Schema (`db_schemas/05_second_brain/`)

```sql
-- Raw inbox log (audit trail)
CREATE TABLE sb_inbox (
    inbox_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    raw_text TEXT NOT NULL,
    slack_ts VARCHAR(50) NULL,
    classification ENUM('person', 'project', 'idea', 'admin', 'unknown') NULL,
    processed_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);

-- People mentioned or to follow up with
CREATE TABLE sb_people (
    person_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    context TEXT NULL,
    last_mentioned DATE NULL,
    follow_up_needed TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);

-- Active projects
CREATE TABLE sb_projects (
    project_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    status ENUM('active', 'paused', 'completed') DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);

-- Ideas to revisit
CREATE TABLE sb_ideas (
    idea_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    tags VARCHAR(500) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);

-- Administrative items
CREATE TABLE sb_admin (
    admin_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    due_date DATE NULL,
    completed TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);
```

### PHP Classes (`classes/SecondBrain/`)

| File | Purpose |
|------|---------|
| `Classifier.php` | Calls Claude API to categorize incoming thoughts |
| `SlackClient.php` | Posts digests back to Slack |
| `DigestGenerator.php` | Queries tables, generates summary via Claude |
| `InboxProcessor.php` | Orchestrates capture → classify → store |

### API Endpoints (`wwwroot/api/second-brain/`)

| Endpoint | Purpose |
|----------|---------|
| `capture.php` | Receives webhook from Slack (via Zapier or direct) |
| `digest.php` | Manual trigger for digest (for testing) |
| `inbox.php` | List/view inbox items |

### Cron Jobs (`cron/`)

| File | Schedule | Purpose |
|------|----------|---------|
| `daily_digest.php` | Daily 7:00 AM | Generate digest, create todos, post to Slack |
| `weekly_review.php` | Sunday 5:00 PM | Weekly summary to Slack |

### Admin UI (`templates/admin/second-brain/`)

| Template | Purpose |
|----------|---------|
| `inbox.tpl.php` | View/manage raw inbox |
| `people.tpl.php` | View/edit people |
| `projects.tpl.php` | View/edit projects |
| `ideas.tpl.php` | View/edit ideas |

## External Dependencies

### Required API Keys (store in `Config.php`)

```php
// Claude API for classification and summarization
public static $claude_api_key = 'sk-ant-...';

// Slack incoming webhook for posting digests
public static $slack_webhook_url = 'https://hooks.slack.com/services/...';
```

### Slack Setup Options

**Option A: Via Zapier (simpler)**
- Zapier watches Slack channel
- Zapier calls your `/api/second-brain/capture.php` endpoint
- Cost: Zapier subscription

**Option B: Direct Slack App (free, more setup)**
- Create Slack app with Events API
- Subscribe to `message.channels` events
- Slack calls your endpoint directly

## Cost Estimate

| Item | Cost |
|------|------|
| Claude API | ~$0.01-0.05 per thought classified |
| Daily digest | ~$0.05-0.10 per day |
| Weekly review | ~$0.10-0.20 per week |
| **Monthly total** | ~$5-15 depending on volume |

No Notion or Zapier subscription required.

## Implementation Phases

### Phase 1: Minimal Loop (MVP)
- `sb_inbox` table only
- `capture.php` endpoint (stores raw, no classification)
- Manual review via phpMyAdmin or simple admin page
- **Validate the capture habit works**

### Phase 2: Classification
- Add Claude API integration
- Add `sb_people`, `sb_projects`, `sb_ideas`, `sb_admin` tables
- Automatic classification on capture

### Phase 3: Daily Digest + TODO Integration
- `daily_digest.php` cron
- `Todo::createTodo()` integration
- Slack posting

### Phase 4: Weekly Review
- `weekly_review.php` cron
- Pattern detection across week

### Phase 5: Admin UI
- Full CRUD for all Second Brain entities
- Linking between entities

## Questions to Resolve

1. **Slack integration method** - Zapier or direct Slack app?
2. **Multi-user support** - Single user for now, or build for multiple users?
3. **Todo generation style** - How many todos per day? Always create, or suggest first?
4. **Classification prompt** - What categories matter most to you?

## Next Steps

1. Decide on Slack integration method
2. Create `db_schemas/05_second_brain/` with initial tables
3. Build `capture.php` endpoint (Phase 1 MVP)
4. Test capture workflow for 1 week
5. Proceed to Phase 2 based on learnings

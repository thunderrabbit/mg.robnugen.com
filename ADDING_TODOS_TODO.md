# Plan for Implementing TODOs

## Database Schema

Based on your feedback, I have revised the schema to be more flexible and integrated with your existing activity system.

### Key Changes
*   **Removed `type` Enum**: Replaced with functional flags and separate scheduling columns.
*   **Flexible Scheduling**: Added `do_days` (Mon-Sun), `do_dates` (5th, 25th), and `do_time` (24h format) to handle complex recurrence simply.
*   **Activity Integration**: Added `activity_id` to link Todo items to `activities` (and indirectly to `activity_kai` sessions).
*   **Timezones**: `todo_logs` will store `completed_at_local` and the `timezone` string captured at the time of logging.
*   **Goals**: `target_count` and `target_duration_seconds` can coexist (e.g., "Meditate 10min, 2x per day").

```sql
-- Main table for defining tasks, habits, and todos
CREATE TABLE todos (
    todo_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,

    -- Functional Configuration
    is_timer TINYINT(1) NOT NULL DEFAULT 0,         -- Requires a timer?
    is_counter TINYINT(1) NOT NULL DEFAULT 0,       -- Requires counting? (target_count > 1)

    -- Integration with Activity System
    activity_id BIGINT UNSIGNED NULL,               -- If set, this todo is completed by doing this activity
                                                    -- FK to activities(activity_id)

    -- Goals
    target_count INT UNSIGNED NULL DEFAULT 1,       -- Default 1 (checkbox). >1 for "8 glasses"
    target_duration_seconds INT UNSIGNED NULL,      -- If is_timer=1, this is the goal per session (e.g. 600)

    -- Scheduling / Recurrence
    -- For "Weekly Tasks" (e.g. Mon, Wed, Fri) use do_days
    do_days SET('Sun','Mon','Tue','Wed','Thu','Fri','Sat') NULL,

    -- For "Monthly Tasks" (e.g. 5th and 25th) use do_dates
    do_dates SET('1','2','3','4','5','6','7','8','9','10',
                 '11','12','13','14','15','16','17','18','19','20',
                 '21','22','23','24','25','26','27','28','29','30','31') NULL,

    -- Target time of day (NULL for flexible/multi-frequency items)
    do_time TIME NULL,                              -- e.g. '08:00:00' for 8am

    -- For One-time items (no recurrence set)
    due_date DATETIME NULL,                         -- Single due date

    -- Status
    is_active TINYINT(1) NOT NULL DEFAULT 1,        -- Soft delete / archive

    -- Timestamps
    created_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

    PRIMARY KEY (todo_id),
    KEY idx_user_todos (user_id, is_active),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (activity_id) REFERENCES activities(activity_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Log table for tracking each completion instance
CREATE TABLE todo_logs (
    log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    todo_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    ak_id BIGINT UNSIGNED NULL,                     -- If set, this todo was completed by this activity_kai
                                                    -- FK to activity_kai(ak_id)

    -- When this instance was completed (local time)
    date_logged DATETIME NOT NULL,                  -- e.g. '2026-01-28 14:30:00'

    -- Which instance is this today? (1st glass of water, 2nd, etc.)
    nth TINYINT UNSIGNED NOT NULL DEFAULT 1,

    -- Duration for timed todos (NULL for simple checkboxes)
    duration_seconds INT UNSIGNED NULL,

    -- Timezone context (captured from browser)
    timezone VARCHAR(64) NULL,                      -- e.g. 'Asia/Tokyo'

    -- Timestamps
    created_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

    PRIMARY KEY (log_id),
    KEY idx_todo_date (todo_id, date_logged),
    KEY idx_user_date (user_id, date_logged),
    FOREIGN KEY (todo_id) REFERENCES todos(todo_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (ak_id) REFERENCES activity_kai(ak_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## Resolved Questions

*   **Recurrence**: Implemented `do_days` (SET), `do_dates` (SET), and `do_time` (TIME) for flexible scheduling.
*   **Timezones**: `todo_logs` captures `completed_at_local` and `timezone`. App logic will rely on browser/client to determine "today".
*   **Activity Integration**: Uses `activity_id`. Logic: When `activity_kai` session finishes, system checks if a Todo exists for that `activity_id` and increments `todo_logs` for "today".
*   **Streaks**: Validated on fly from `todo_logs` (Strict consecutive).
*   **Multi-Frequency**: Each completion is a separate row with its own `nth` value (1, 2, 3...) and `date_logged` timestamp.

---

## User Stories

### "A Day in the Life" (The Easy Case)
*Scenario: Wake up, brush teeth, drink water 1 of 8, take supplements, meditate 1 of 2 (timed), work (timed), water 2 of 8, enjoy sunshine (timed), water 3 of 8, networking, shower, meditate 2 of 2, sleep (timed).*

### Schema Mapping
Here is how these activities map to the proposed `todos` table:

| Activity | Type | Config | Schema Mapping (`todos`) |
| :--- | :--- | :--- | :--- |
| **Wake Up** | Simple Habit | Daily | `is_timer=0`, `is_counter=0`, `target_count=1` |
| **Brush Teeth** | Simple Habit | Daily | `is_timer=0`, `is_counter=0`, `target_count=1` |
| **Block Therapy** | Timed 30 minutes | Daily 8am | `is_timer=1`, `is_counter=0`, `target_count=1`, `target_duration_seconds=1800`, `do_days='Mon,Tue,Wed,Thu'`, `do_time='08:00:00'` |
| **Drink Water** | Counter | Daily (8x) | `is_timer=0`, `is_counter=1`, `target_count=8` |
| **Take Supplements** | Simple Habit | Daily | `is_timer=0`, `is_counter=0`, `target_count=1` |
| **Meditate** | Timed + Count | Daily (2x) | `is_timer=1`, `target_count=2`, `activity_id` linked (e.g. 1) |
| **Work** | Timed | Daily | `is_timer=1`, `target_duration=X`, `activity_id` linked (e.g. 4) |
| **Enjoy Sunshine** | Timed | Daily | `is_timer=1`, `activity_id` linked (New activity needed?) |
| **Networking** | Simple/Timed? | Daily | `is_timer=1`, `activity_id` linked (e.g. 3) |
| **Shower** | Simple Habit | Daily | `is_timer=0`, `is_counter=0`, `target_count=1` |
| **Sleep** | Timed | Daily (7h+) | `is_timer=1`, `target_duration=25200` (7h), `activity_id` linked (e.g. 2) |


**Note**: Each completion creates a new row in `todo_logs` with its own `nth` value (1, 2, 3...) and timestamp. Timed actions also store `duration_seconds`.

### Data Simulation: End of Day State

Assuming `user_id=1`, `date_logged='2026-01-28'`, and sample `todo_id`s, here is what the database tables would look like after completing the scenario:

**Scenario Recap**:
1.  Wake up (Habit)
2.  Brush Teeth (Habit)
3.  Water (Counter 1/8)
4.  Supplements (Habit)
5.  Meditate (Timer 1/2) - 10 min
6.  Work (Timer) - 4 hours
7.  Water (Counter 2/8)
8.  Enjoy Sunshine (Timer) - 15 min
9.  Water (Counter 3/8)
10. Networking (Timer) - 30 min
11. Shower (Habit)
12. Meditate (Timer 2/2) - 10 min
13. Sleep (Timer) - 7 hours

#### `activity_kai` (The raw sessions)
*Note: Only timed events generate these rows.*

| ak_id | activity_id | Name | intended_sec | actual_sec | start_local_dt |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **500** | 1 | Meditate | 600 | 600 | 07:00:00 |
| **501** | 4 | Work | 14400 | 14400 | 09:00:00 |
| **502** | 99 | Sunshine | 900 | 900 | 13:00:00 |
| **503** | 3 | Networking | 1800 | 1800 | 14:00:00 |
| **504** | 1 | Meditate | 600 | 600 | 20:00:00 |
| **505** | 2 | Sleep | 25200 | 25200 | 22:00:00 |

#### `todo_logs` (Each completion instance)
*Note: One row per completion. Multi-frequency todos (Water, Meditate) have multiple rows.*

| log_id | todo_id | Title | nth | date_logged | duration | ak_id |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| 1001 | 101 | Wake Up | 1 | 06:30 (local datetime) | NULL | NULL |
| 1002 | 102 | Brush Teeth | 1 | 06:35 (local datetime) | NULL | NULL |
| 1003 | 103 | Drink Water | 1 | 06:40 (local datetime) | NULL | NULL |
| 1004 | 104 | Supplements | 1 | 06:45 (local datetime) | NULL | NULL |
| 1005 | 105 | Meditate | 1 | 07:00 (local datetime) | 600 | 500 |
| 1006 | 106 | Work | 1 | 09:00 (local datetime) | 14400 | 501 |
| 1007 | 103 | Drink Water | 2 | 12:00 (local datetime) | NULL | NULL |
| 1008 | 107 | Sunshine | 1 | 13:00 (local datetime) | 900 | 502 |
| 1009 | 103 | Drink Water | 3 | 13:30 (local datetime) | NULL | NULL |
| 1010 | 108 | Networking | 1 | 14:00 (local datetime) | 1800 | 503 |
| 1011 | 109 | Shower | 1 | 19:30 (local datetime) | NULL | NULL |
| 1012 | 105 | Meditate | 2 | 20:00 (local datetime) | 600 | 504 |
| 1013 | 110 | Sleep | 1 | 22:00 (local datetime) | 25200 | 505 |

## Next Steps

1.  **Draft Migration**: Create the actual `.sql` file for schema migration.
2.  **Backend Logic**: Plan the PHP classes for `Todo` and `TodoLog` management.
3.  **Frontend**: Update UI to support creating these complex Todo types.

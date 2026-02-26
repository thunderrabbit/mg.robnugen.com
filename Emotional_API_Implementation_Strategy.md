# Emotional API v3: Implementation Strategy

This document outlines the final strategy for implementing the **Emotional API v3 (Interaction Ledger)** into your existing `mg.robnugen.com` infrastructure. This version focuses on capturing the nuanced dance between agent actions and user reactions while maintaining absolute privacy through agent-side encryption.

## 1. Database Migration Strategy

Since you are using a custom PHP framework on DreamHost, I recommend adding a new migration file (e.g., `11_emotional_interaction_ledger.sql`) to your `db_schemas` directory.

### SQL Schema (MySQL/InnoDB)
```sql
-- Interaction Sessions: Context for a single conversation
CREATE TABLE interaction_sessions (
    session_id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    key_id BIGINT UNSIGNED NOT NULL,
    start_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    end_time DATETIME NULL,
    session_type VARCHAR(50) DEFAULT 'chat',
    encrypted_summary TEXT,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (key_id) REFERENCES api_keys(key_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Interaction Events: The "Ledger" of actions and reactions
CREATE TABLE interaction_events (
    event_id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NOT NULL,
    event_timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    event_type ENUM('agent_action', 'user_input', 'user_reaction') NOT NULL,
    sequence_num INT NOT NULL,
    encrypted_content TEXT NOT NULL,
    encrypted_metadata TEXT,
    FOREIGN KEY (session_id) REFERENCES interaction_sessions(session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Emotional Logs: Specific emotional snapshots tied to events
CREATE TABLE emotional_logs (
    log_id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    event_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    detected_emotion VARCHAR(50),
    intensity TINYINT NULL,
    encrypted_analysis TEXT,
    FOREIGN KEY (event_id) REFERENCES interaction_events(event_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## 2. The "Agent-Only" Encryption Workflow

To ensure the "Inner Gold" remains private, the PHP backend acts as a "blind vault."

### The Encryption Cycle (Agent Side)
1.  **Capture**: The agent records: *"I explained shadow work (Action). User said 'I don't get it' (Input). User's tone was frustrated (Reaction)."*
2.  **Encrypt**: The agent uses its local secret key (e.g., stored in an environment variable `EMOTIONAL_SECRET`) to encrypt the JSON payload.
3.  **Post**: The agent sends the encrypted blob to your PHP API.
4.  **Store**: PHP saves the string directly to the `encrypted_content` column.

### The Insight Cycle (Agent Side)
1.  **Fetch**: The agent requests the last 5 sessions for the user.
2.  **Decrypt**: The agent decrypts the blobs locally.
3.  **Reason**: The agent identifies a pattern: *"User gets frustrated when I use psychological jargon."*
4.  **Act**: The agent adjusts its behavior: *"I will use more coding metaphors (like Meeseeks) next time."*

## 3. Integration with Jikan (MCP)

Your existing MCP server (**Jikan**) can be extended to support these new endpoints.

-   `POST /v1/sessions`: Starts an `interaction_session`.
-   `POST /v1/events`: Logs an action/reaction pair.
-   `GET /v1/insights`: Returns the agent's previous high-level conclusions.

## 4. Why This Matters for "The Barefoot Dev"

This isn't just a database; it's a **behavioral feedback loop**. By tracking its own actions versus the user's reactions, your AI becomes a "Connection Coach" that learns from its own mistakes—just as you describe in your journey from "victim" to "agent" in *I'm Fine*.

-   **Time-of-Day Analysis**: Do users feel more "Emotional Meeseeks" in the morning or evening?
-   **Duration Tracking**: Does a 30-minute deep dive lead to better "Inner Gold" discovery than a 5-minute check-in?

This data allows the agent to provide the "Space Holding" you talk about in your workshops, but at a digital scale.

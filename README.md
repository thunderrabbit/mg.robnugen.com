# 🧘 Meiso Gambare

> Helps you focus on what's important for you.

Meiso Gambare is a tool designed to help you build habits and stay focused. It combines a distraction-free timer with activity tracking and todo management to support your personal goals.

## 🚀 Features

- **Focus Timer**: A simple, distraction-free countdown timer for important activities.
- **Bonus Time**: Finished timer then counts *up* to see how long you stayed in the zone.
- **Activity Tracking**: Log your sessions and track your consistency.
- **Todo Integration**: Link timers to specific tasks to auto-complete them.
- **Habit Building**: Track streaks and recurrences. (coming soon?)
- **Private & Secure**: User-based authentication and restricted access.
- **Emotional Interaction Ledger**: Encrypted behavioral tracking API for AI agents.

---

## 🔐 Emotional Interaction Ledger

The Emotional Interaction Ledger (`/api/v1/emotions/`) lets AI agents log and query behavioral interaction events — vocab labels, timestamped events, and auto-grouped sessions — all encrypted at rest.

### Encryption

All sensitive data (vocab state labels and event content) is encrypted before storage using **XSalsa20-Poly1305** (libsodium `sodium_crypto_secretbox`):

- **Key derivation**: `HMAC-SHA256("emotional_v1", raw_api_key)` produces a 32-byte per-key encryption key.
- **Fresh nonce per write**: Each encryption uses a random 24-byte nonce, so identical plaintext produces different ciphertext.
- **Authenticated encryption**: The Poly1305 MAC detects tampering or wrong-key decryption attempts.
- **Zero plaintext at rest**: Both the `state` column (vocab) and `encrypted_content` column (events) store only ciphertext.
- **Decryption requires the raw API key**: The server derives the key on each request. If an API key is revoked, its encrypted data becomes permanently unreadable.

### Data Model

- **Vocab** (`my_ids_for_my_users_state`): Agent-private labels mapped to random numeric handles (`my_id`).
- **Events** (`interaction_events`): Timestamped entries with type (`agent_action`, `user_input`, `user_reaction`), encrypted content, and optional vocab tag.
- **Sessions** (`interaction_sessions`): Auto-grouped by a configurable inactivity gap. No decryption needed to list sessions.

---

## 📝 License

Copyright (C) 2026 Rob Nugen

Licensed under the GNU General Public License v3.0. See the `LICENSE` file for details.

---

## 🛠️ Development & Installation

This project is built on a minimalist custom PHP framework tailored for DreamHost shared hosting.

### 📂 Structure

- `classes/`: Core logic (Auth, ActivityTracking, Todo, Database, Billing, Emotional).
- `wwwroot/`: Public-facing files (API endpoints, controllers).
- `templates/`: View layer using a lightweight custom template engine.
- `css/`: Soft blue aesthetic with clean panels.

### 🔧 Setup (DreamHost Deployment)

1. **Set up a DreamHost new user account:**
   - Clone [thunderrabbit/new-DH-user-account](https://github.com/thunderrabbit/new-DH-user-account)

2. **Set your domain's Web Directory in DreamHost panel:**
   - e.g. `/home/dh_user/example.com/wwwroot`

3. **Clone this repo on your server**:
   - Clone to `/home/dh_user/example.com`
   - ⚠️ Be aware of DreamHost system links like `.dh-diag → /dh/web/diag` — **The symlink is owned by `root`**.

4. **Customize the templates:**
   - `/templates/layout/admin_base.tpl.php`: Main layout
   - `/templates/admin/index.tpl.php`: Admin dashboard

5. **Visit `/`** to automatically create admin user in the freshly set up MySQL tables.

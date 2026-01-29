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

---

## 📝 License

Copyright (C) 2026 Rob Nugen

Licensed under the GNU General Public License v3.0. See the `LICENSE` file for details.

---

## 🛠️ Development & Installation

This project is built on a minimalist custom PHP framework tailored for DreamHost shared hosting.

### 📂 Structure

- `classes/`: Core logic (User, Todo, Timer, Database).
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

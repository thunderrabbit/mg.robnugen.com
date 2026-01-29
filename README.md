# Meiso Gambare

> Helps you focus on what's important for you.

Meiso Gambare is a tool designed to help you build habits and stay focused. It combines a distraction-free timer with activity tracking and todo management to support your personal goals.

## � Features

- **Focus Timer**: A simple, distraction-free countdown timer for authorized activities.
- **Activity Tracking**: Log your sessions and track your consistency.
- **Todo Integration**: Link timers to specific tasks to auto-complete them.
- **Habit Building**: Track streaks and recurrences.
- **Private & Secure**: User-based authentication and restricted access.

---

## 📝 License

Copyright (C) 2026 Rob Nugen

Licensed under the GNU General Public License v3.0. See the `LICENSE` file for details.

---

## �️ Development & Installation

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

3. **Clone this repo locally** into a working directory.

4. **Configure your deploy script:**
   - Edit `scp_files_to_dh.sh` to point to your DH username and target path.

5. **Clone this repo server-side** (optional but useful):
   - Clone to `/home/dh_user/example.com`
   - ⚠️ Be aware of DreamHost system links like `.dh-diag → /dh/web/diag` — **The symlink is owned by `root`**.

6. **Deploy with `scp_files_to_dh.sh`** or manually sync files.

7. **Customize the templates:**
   - `/templates/layout/admin_base.tpl.php`: Main layout
   - `/templates/admin/index.tpl.php`: Admin dashboard

8. **Visit `/`** to automatically create admin user in the freshly set up MySQL tables.

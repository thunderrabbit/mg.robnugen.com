# User Benefits by Account Type

## Logged-In Free User Benefits

| Feature | Anonymous | Logged-In (Free) |
|---------|-----------|------------------|
| Timer works | Yes | Yes |
| **Sessions saved to DB** | No | Yes |
| **View session history** | No | Yes |
| **Create custom activities** | No | Yes |
| **Delete sessions** | No | Yes |
| View public sessions | Yes | Yes |
| Activities available | Meditation only | Meditation + custom |

### Key Benefits

1. **Session Persistence** - Activity sessions are saved to `activity_kai` table and can be retrieved later

2. **Session History** - Access to:
   - `/api/list-active-sessions.php` - running timers
   - `/api/list-completed-sessions.php` - past sessions with pagination

3. **Custom Activities** - Can create their own activities via the "+ Add new..." dropdown

4. **Session Management** - Can stop and delete their own sessions

---

## Pro/Admin Only Features

- **Session Keys/URLs** - Only admins get shareable `/mg/{session_key}` URLs (from `start-activity.php:72-75`)
- **Pro Activities** - Sleeping, Networking, Work, Physical activity, Hard mode, Creativity, Minecraft (all `is_pro = 1`)
- **Dashboard** - The `/` dashboard only shows for admins
- **Multiple concurrent timers** - Session keys enable this for admins

---

## Summary

The main value for free logged-in users is **session history** and **custom activities**.

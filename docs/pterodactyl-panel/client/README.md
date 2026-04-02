# Pterodactyl Client API

The Client API is used for operations relative to the authenticated user and their servers. Each user can generate their own API key from the Account Settings section of the User Dashboard.

## Endpoints

### [Account](./account.http)
Operations for the current user:
- Get account details
- Update email and password
- Manage account API keys
- Manage/Remove SSH keys

### [Servers](./servers.http)
Operations for a specific server (requires `server_id`):
- Get server details and real-time resource usage
- Send power actions (start, stop, etc.)
- Send commands to the console
- Manage files (list, view, upload, delete, etc.)
- Manage databases, schedules, backups, etc.

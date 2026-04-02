# Pterodactyl Application API

The Application API is used for admin-level operations. These endpoints require an API key with the appropriate permissions from the Application API section of the Admin Panel.

## Endpoints

### [Users](./users.http)
Operations for managing panel users:
- List/View Users
- Create/Update/Delete Users
- Generate SSO tokens

### [Nodes](./nodes.http)
Operations for managing physical nodes:
- List/View Nodes
- Create/Update/Delete Nodes
- Manage Allocations

### [Hyper Nodes](./hyper-nodes.md) ([HTTP](./hyper-nodes.http))
Advanced lifecycle management for user-owned nodes:
- Automated Deployment/Creation
- Global Upgrade/Cloud Scaling
- Suspension/Activation Lifecycle
- IP Resource Auto-Release

### [Servers](./servers.http)
Operations for managing all servers on the panel:
- List/View Servers
- Update details, build settings, and startup configurations
- Suspend/Unsuspend/Reinstall servers
- Delete servers (including database management)

### [Nests & Eggs](./nests_eggs.http)
Operations for managing nests, eggs and profiles:
- List/View Nests
- List/View Eggs in Nest
- [List Egg Profiles](./egg-profiles.md) ([HTTP](./nests_eggs.http))

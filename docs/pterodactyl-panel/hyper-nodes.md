# Hyper Nodes API Documentation

Hyper Nodes are specialized Pterodactyl nodes that are owned and managed by end-users. This API allows for the complete lifecycle management of these nodes, including automated deployment, resource scaling, and suspension.

## Base URL
`https://your-panel.com/api/application/hyper-nodes`

## Endpoints

### 1. Store Hyper Node
`POST /`
Creates and initializes a new Hyper Node. This will trigger the automated deployment and allocation process.

**Body Params:**
- `name` (string, required): Display name of the node.
- `owner_id` (int, required): The ID of the user who will own the node.
- `location_id` (int, required): The physical location for the node.
- `limits` (object): `memory`, `cpu`, `disk` settings.
- `feature_limits` (object): `databases`, `allocations`, `backups` settings.
- `deploy` (object): `locations` (array), `port_range` (array).

---

### 2. Upgrade Hyper Node
`POST /{id}/upgrade`
Upgrades the hardware limits of an existing Hyper Node.

**Body Params:**
- `memory` (int): New memory limit in MB.
- `cpu` (int): New CPU limit in %.
- `disk` (int): New disk limit in MB.

---

### 3. Install Hyper Node
`POST /{id}/install`
Re-triggers the installation process for the node's Wings and environment.

---

### 4. Update Hyper Node
`POST /{id}/update`
Synchronizes the node's configuration and resources.

---

### 5. Restart Hyper Node
`POST /{id}/restart`
Restarts the Wings daemon running on the Hyper Node.

---

### 6. Suspend Hyper Node
`POST /{id}/suspend`
Suspends the Hyper Node. This will:
1. Suspend all child Pterodactyl servers belonging to this node.
2. Put the underlying Proxmox VM into a suspended state.
3. Update the node status to `suspended`.

---

### 7. Unsuspend Hyper Node
`POST /{id}/unsuspend`
Restores a suspended Hyper Node. This will:
1. Re-activate all child servers.
2. Resume the underlying Proxmox VM execution.
3. Update the node status to `active`.

---

### 8. Destroy Hyper Node
`DELETE /{id}`
Securely deletes the Hyper Node and all associated data.
1. Force-stops and deletes the Proxmox VM.
2. Recursively deletes all child servers.
3. **Important**: Automatically releases the associated IP address back to the Allocation Controller pool (if auto-allocated).

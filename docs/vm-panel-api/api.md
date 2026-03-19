# VM Panel API Documentation

This documentation provides details on how to integrate with the VM Panel API.

## Base URLs

- **Main API**: `/api`
- **Auth API**: `/api/auth`
- **Client API**: `/api/client`
- **Admin API**: `/api/admin`

---

## Authentication

Authentication is handled via session cookies for browsers or Bearer tokens.

### Login

- **URL**: `/api/auth/login`
- **Method**: `POST`
- **Request Body**:
  ```json
  {
    "email": "user@example.com",
    "password": "yourpassword",
    "remember": true
  }
  ```
- **Response**: `200 OK` on success.

### Login Checkpoint (2FA)

- **URL**: `/api/auth/checkpoint`
- **Method**: `POST`
- **Request Body**:
  ```json
  {
    "email": "user@example.com",
    "password": "yourpassword",
    "checkpoint_code": "123456",
    "totp_code": "123456",
    "totp_recovery": "recovery_token_here",
    "sso_token": "optional_jwt_token",
    "remember": true
  }
  ```
  _(Note: Include `totp_code` or `totp_recovery` depending on the 2FA method used)_

### Request Password Reset

- **URL**: `/api/auth/password/reset`
- **Method**: `POST`
- **Request Body**:
  ```json
  {
    "email": "user@example.com",
    "captcha_response": "optional_captcha_token"
  }
  ```

### Confirm Password Reset

- **URL**: `/api/auth/password/reset/:session_id`
- **Method**: `POST`
- **Request Body**:
  ```json
  {
    "password": "new_secure_password",
    "captcha_response": "optional_captcha_token"
  }
  ```

### Logout

- **URL**: `/api/auth/logout`
- **Method**: `DELETE`
- **Headers**: `Authorization: Bearer <token>` (if using tokens) or rely on session cookie.

---

## Client API

Endpoints for users to manage their resources.

### Servers

- **List Servers**: `GET /api/client/servers`
  - **Query Parameters**:
    - `page` (int): Page number.
    - `per_page` (int): Items per page.
    - `search` (string): Search text.
    - `name` (string): Filter by name.
    - `view_all` (bool): View all servers (if applicable).
    - `include_os` (bool): Include operating system details.
    - `include_details` (bool): Include hardware details and resource usage.
    - `include_disks` (bool): Include attached disks.
    - `include_networks` (bool): Include networking interfaces.

- **Get Server Details**: `GET /api/client/servers/:id`
  - **Query Parameters**:
    - `include_os`, `include_details`, `include_disks`, `include_networks`
  - **Response**: The detailed server object.

- **Update Server**: `PUT /api/client/servers/:id`
  - **Request Body**:
    ```json
    {
      "name": "New Server Name",
      "dns1": "1.1.1.1",
      "dns2": "8.8.8.8",
      "dns1_v6": "2606:4700:4700::1111",
      "dns2_v6": "2606:4700:4700::1001",
      "network_id": 1,
      "disk_id": 1
    }
    ```

- **List Activity Logs**: `GET /api/client/servers/:id/activity`
  - **Query Parameters**: `page`, `per_page`, `search`, `event`
- **List Disks**: `GET /api/client/servers/:id/disks`
  - **Query Parameters**: `page`, `per_page`, `search`
- **List Networks**: `GET /api/client/servers/:id/networks`
  - **Query Parameters**: `page`, `per_page`, `search`

### VM Console Actions

- **Start**: `GET /api/client/servers/:id/console/start`
- **Stop**: `GET /api/client/servers/:id/console/stop`
- **Force Stop**: `GET /api/client/servers/:id/console/force-stop`
- **Restart**: `GET /api/client/servers/:id/console/restart`

### Server Options

- **OS Installation**: `POST /api/client/servers/:id/options/install`
  - Body: `{ "image_id": 1, "commands": [["cmd", "arg"]] }`
- **Get Install Status**: `GET /api/client/servers/:id/options/install`
- **Reset Password**: `POST /api/client/servers/:id/options/reset-password`
  - Body: `{ "current_password": "..." }`
- **Boot Mode**: `POST /api/client/servers/:id/options/boot-mode`
  - Body: `{ "mode": "recovery|os", "image_id": 1 }`
- **Setup Network**: `GET /api/client/servers/:id/options/setup-network`

### VNC

- **Enable VNC**: `POST /api/client/servers/:id/vnc/enable`
- **Disable VNC**: `POST /api/client/servers/:id/vnc/disable`

### Account

Endpoints for managing the current user's account.

#### Get Account Info

- **URL**: `/api/client/account`
- **Method**: `GET`
- **Response**: The current user's profile and settings.

#### Update Account Password

- **URL**: `/api/client/account`
- **Method**: `PUT`
- **Request Body**:
  ```json
  {
    "current_password": "old_password",
    "password": "new_password"
  }
  ```

#### Generate 2FA Secret

- **URL**: `/api/client/account/two-factor`
- **Method**: `GET`
- **Response**: Contains the `secret` and an array of `recovery_tokens`.

#### Enable 2FA

- **URL**: `/api/client/account/two-factor`
- **Method**: `POST`
- **Request Body**:
  ```json
  {
    "code": "123456",
    "current_password": "yourpassword"
  }
  ```

#### Disable 2FA

- **URL**: `/api/client/account/two-factor/deactivate`
- **Method**: `POST`
- **Request Body**:
  ```json
  {
    "code": "123456",
    "current_password": "yourpassword"
  }
  ```

### Images

- **List Images**: `GET /api/client/images`

---

## Admin API

Administrator-only endpoints.

### Overview

- **Get Admin Overview**: `GET /api/admin/overview`
  - **Response**: Summary metrics of the system.

### Activity Logs

- **List Activity Logs**: `GET /api/admin/activity`
  - **Query Parameters**:
    - `page` (int), `per_page` (int)
    - `after_date` (RFC3339 datetime)
    - `actor_id` (int)
    - `event` (string)
    - `ip` (string)
    - `admin_only` (bool)

### API Keys

- **List API Keys**: `GET /api/admin/api-keys`
  - **Query Parameters**: `page`, `per_page`
- **Create API Key**: `POST /api/admin/api-keys`
  - **Request Body**:
    ```json
    {
      "description": "Integration Key",
      "permissions": ["servers.read", "servers.create"],
      "expires_at": "2026-12-31T23:59:59Z"
    }
    ```

### Hypervisors

- **List Hypervisors**: `GET /api/admin/hypervisors`
- **Create Hypervisor**: `POST /api/admin/hypervisors`
  - **Request Body**:
    ```json
    {
      "name": "Node 1",
      "location_id": 1,
      "description": "Main Node",
      "fqdn": "node1.example.com",
      "max_server": 50,
      "max_memory": 128000,
      "maintenance_mode": false,
      "https": true,
      "vnc_ip": "192.168.1.10",
      "strict_traffic_in": true,
      "strict_traffic_out": true
    }
    ```

- **Detail/Update/Delete**: `GET|PUT|DELETE /api/admin/hypervisors/:id`
- **Rotate Token**: `GET /api/admin/hypervisors/:id/rotate-token`
- **Get Config**: `GET /api/admin/hypervisors/:id/configuration`

### Hypervisor Storages

- **List Storages**: `GET /api/admin/hypervisors/:id/storages`
- **Create Storage**: `POST /api/admin/hypervisors/:id/storages`
  - **Request Body**:
    ```json
    {
      "max_size": 1000000,
      "proxmox_id": "local-lvm",
      "description": "Primary fast storage"
    }
    ```
- **Update/Delete**: `PUT|DELETE /api/admin/hypervisors/:id/storages/:storage_id`

### Users

- **List Users**: `GET /api/admin/users`
  - **Query Parameters**: `email`, `page`, `per_page`
- **Create User**: `POST /api/admin/users`
  - **Request Body**:
    ```json
    {
      "email": "user@example.com",
      "username": "exampleuser",
      "first_name": "John",
      "last_name": "Doe",
      "password": "SecurePassword123!",
      "is_admin": false
    }
    ```
- **Detail/Update/Delete**: `GET|PUT|DELETE /api/admin/users/:id`
- **Generate SSO Token**: `GET /api/admin/users/:id/sso`
  - **Response**: Contains a JWT token for SSO authentication.

### Servers (Admin Access)

- **List Servers**: `GET /api/admin/servers`
  - **Query Parameters**: `hypervisor_id`, `network_id`, `name`, `page`, `per_page`
- **Create Server**: `POST /api/admin/servers`
  - **Request Body**:
    ```json
    {
      "owner_id": 1,
      "hypervisor_id": 1,
      "disk_id": 1,
      "network_id": 1,
      "name": "My VM",
      "cpu": 2,
      "memory": 2048,
      "max_traffic_in": 1000,
      "max_traffic_out": 1000,
      "network_rate": 10000,
      "dns1": "1.1.1.1",
      "dns2": "8.8.8.8",
      "dns1_v6": "2606:4700:4700::1111",
      "dns2_v6": "2606:4700:4700::1001"
    }
    ```
- **Deploy Server**: `POST /api/admin/servers/deploy`
  - **Request Body**:
    ```json
    {
      "location_id": 1,
      "owner_id": 1,
      "disk_size": 20,
      "name": "My VM",
      "cpu": 2,
      "memory": 2048,
      "max_traffic_in": 1000,
      "max_traffic_out": 1000,
      "network_rate": 10000,
      "dns1": "1.1.1.1",
      "dns2": "8.8.8.8",
      "dns1_v6": "2606:4700:4700::1111",
      "dns2_v6": "2606:4700:4700::1001"
    }
    ```
- **SendCommand**: `POST /api/admin/servers/:id/send-command`
  - Body: `{ "command": ["ls", "-la"] }`
- **Detail/Update/Delete**: `GET|PUT|DELETE /api/admin/servers/:id`
- **Force Delete**: `DELETE /api/admin/servers/:id/force`

- **External Access**:
  - `GET /api/admin/servers/external/:id`
  - `PUT /api/admin/servers/external/:id`
  - `POST /api/admin/servers/external/:id/send-command`

### Server Disks

- **List/Create**: `GET|POST /api/admin/server-disks`
  - **POST Request Body**:
    ```json
    {
      "server_id": 1,
      "hypervisor_storage_id": 1,
      "size": 20,
      "note": "Primary disk"
    }
    ```
- **Detail/Update/Delete**: `GET|PUT|DELETE /api/admin/server-disks/:id`
- **Force Delete**: `DELETE /api/admin/server-disks/:id/force`

### Networks

- **List Networks**: `GET /api/admin/networks`
- **Create Network**: `POST /api/admin/networks`
  - **Request Body**:
    ```json
    {
      "hypervisor_id": 1,
      "mac_id": "00:00:00:00:00:00",
      "ip": "192.168.1.100",
      "subnet": "255.255.255.0",
      "gateway": "192.168.1.1"
    }
    ```
- **Detail/Update/Delete**: `GET|PUT|DELETE /api/admin/networks/:id`
- **Detach**: `PUT /api/admin/networks/:id/detach`

### Locations

- **List Locations**: `GET /api/admin/locations`
- **Create Location**: `POST /api/admin/locations`
  - **Request Body**:
    ```json
    {
      "label": "US-East-1",
      "description": "Primary Datacenter"
    }
    ```
- **Detail/Update/Delete**: `GET|PUT|DELETE /api/admin/locations/:id`

### Images

- **List/Create**: `GET|POST /api/admin/images`
- **Detail/Update/Delete**: `GET|PUT|DELETE /api/admin/images/:id`

### Image Groups

- **List/Create**: `GET|POST /api/admin/image-groups`
- **Detail/Update/Delete**: `GET|PUT|DELETE /api/admin/image-groups/:id`

### Settings

- **General**: `GET|POST /api/admin/settings/general`
  - **POST Request Body**:
    ```json
    {
      "app_default_language": "en",
      "app_enable_2fa": true,
      "app_company_name": "My Hosting"
    }
    ```
- **Mail**: `GET|POST /api/admin/settings/mail`
  - **POST Request Body**:
    ```json
    {
      "smtp_host": "smtp.example.com",
      "username": "smtp_user",
      "password": "smtp_password",
      "smtp_port": "587",
      "smtp_encryption": "TLS",
      "mail_from": "noreply@example.com",
      "mail_from_name": "My Hosting",
      "website_url": "https://example.com"
    }
    ```
  - **Test Mail**: `POST /api/admin/settings/mail/test`
    - Body: `{ "to": "user@example.com" }`

- **Captcha**: `GET|POST /api/admin/settings/captcha`
  - **POST Request Body**:
    ```json
    {
      "enabled": true,
      "site_key": "your_site_key_here",
      "secret_key": "your_secret_key_here"
    }
    ```
- **Retention**: `GET|POST /api/admin/settings/retention`
  - **POST Request Body**:
    ```json
    {
      "activity_log_retention_days": 30,
      "job_retention_days": 7
    }
    ```
    _(Note: `0` means infinite retention)_

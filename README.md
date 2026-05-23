# files_sharding — Trusted federations for Nextcloud

Distribute users across Nextcloud nodes.

**Author:** Frederik Orellana, Technical University of Denmark (fror@dtu.dk) — developed for the ScienceData cloud platform.  
**License:** AGPL-3.0

## Overview

`files_sharding` implements a master/silo architecture:

- One **master** Nextcloud instance acts as the central identity and redirect hub. Users log in at the master URL and are immediately redirected to their assigned silo.
- N **silo** Nextcloud instances each run a full, independent Nextcloud installation. A user's files live entirely on their silo.
- **Federated shares** link silos together using stable `user@master` identities, so users on different silos can share files without knowing which silo the other user is on.
- The master holds the server registry and user-to-silo assignments. Silos report free space back to the master; new users are auto-assigned to the least-loaded silo.

WebDAV and desktop/mobile sync clients connect directly to the silo after the initial redirect. Write operations (PUT, MKCOL, etc.) are never proxied — clients must target the silo URL directly.

## Requirements

- Nextcloud 34+ on all nodes
- PHP 8.2+
- All nodes must share the same `files_sharding_shared_secret` in `config.php`
- The master must be reachable by all silos and vice versa
- SAML login is recommended so users authenticate at the master and are redirected transparently

## Installation

Enable the app on **every** node (master and all silos):

```bash
occ app:enable files_sharding
```

Then configure each node and register the silo URLs with the master.

## Configuration

### config.php keys

| Key | Required | Description |
|-----|----------|-------------|
| `files_sharding_shared_secret` | All nodes | Long random string. Authenticates inter-server API calls. |
| `files_sharding_master` | Master only | Set to `true`. Omit or `false` on silos. |
| `files_sharding_master_url` | Silos | Public URL of the master, e.g. `https://sciencedata.dk`. |
| `files_sharding_master_internal_url` | Silos (optional) | Internal URL for silo→master calls. Falls back to `files_sharding_master_url`. |

### Registering silos

On the master, use the OCC commands to register each silo and assign users:

```bash
# Register a silo
occ files-sharding:add-server --url https://silo1.example.org \
    --internal-url http://10.0.0.2 --site "Copenhagen" --total-gb 4000

# Assign a user to a specific silo
occ files-sharding:assign-user alice 1        # 1 = server ID

# Auto-assign a user to the least-loaded silo
occ files-sharding:auto-assign alice

# List registered silos
occ files-sharding:list-servers

# List user assignments
occ files-sharding:list-users
```

## Architecture

### Server registry

The master stores silo metadata in `files_sharding_servers` (url, internal_url, site, total_gb, free_gb, x509_dn, user_regex). User-to-silo assignments live in `files_sharding_user_servers`.

### Login redirect flow

1. User logs in at the master.
2. `PostLoginListener` looks up the user's assigned silo.
3. Master issues a short-lived token and redirects the browser to `https://silo/index.php/apps/files_sharding/login?token=…`.
4. The silo validates the token with the master (`/internal/token/validate`) and creates a local NC session.

### WebDAV

The desktop sync client and WebDAV clients need the **silo URL**, not the master URL. Nextcloud's own WebDAV client follows the `X-NC-SiloURL` header set on redirect; generic WebDAV clients must be pointed at the silo directly.

### Federation

Users are identified as `user@masterhost` in federation. The master proxies share acceptance and syncs share state to the correct silo via the internal API.

## API

### OCS (admin-authenticated)

| Method | URL | Description |
|--------|-----|-------------|
| `GET` | `/ocs/v2.php/apps/files_sharding/api/v1/servers` | List silos |
| `POST` | `/ocs/v2.php/apps/files_sharding/api/v1/servers` | Register silo |
| `PUT` | `/ocs/v2.php/apps/files_sharding/api/v1/servers/{id}` | Update silo |
| `DELETE` | `/ocs/v2.php/apps/files_sharding/api/v1/servers/{id}` | Remove silo |
| `GET` | `/ocs/v2.php/apps/files_sharding/api/v1/users/{userId}/server` | Get user's silo |
| `POST` | `/ocs/v2.php/apps/files_sharding/api/v1/users/{userId}/server` | Assign user to silo |
| `DELETE` | `/ocs/v2.php/apps/files_sharding/api/v1/users/{userId}/server` | Unassign user |

### Internal (shared-secret Bearer token)

Called node-to-node; no Nextcloud session required.

| Method | URL | Description |
|--------|-----|-------------|
| `POST` | `/internal/token` | Issue login token (master) |
| `POST` | `/internal/token/validate` | Validate token (silo→master) |
| `POST` | `/internal/servers/{id}/free` | Update free GB (silo→master) |
| `GET` | `/internal/servers` | List servers (any→master) |
| `GET` | `/internal/users/search` | Search users |
| `GET` | `/internal/users/{userId}/external-shares` | Export share state |
| `POST` | `/internal/users/{userId}/sync-shares` | Sync incoming shares |
| `POST` | `/internal/shares/proxy-accept` | Proxy share acceptance |
| `POST` | `/internal/users/{userId}/update` | Propagate user changes |
| `POST` | `/internal/users/{userId}/delete` | Propagate user deletion |

## Development

No build step. Pure PHP + plain JS.

```bash
# Deploy to one node
rsync -av --delete apps/files_sharding/ server:/var/www/nextcloud/apps/files_sharding/

# Run pending migrations after schema changes
occ migrations:execute files_sharding <VersionClass>
```

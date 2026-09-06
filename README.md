# files_sharding — Trusted federations for Nextcloud

Distribute users across Nextcloud nodes.

**Author:** Frederik Orellana, Technical University of Denmark (fror@dtu.dk) — developed for the ScienceData cloud platform.  
**License:** AGPL-3.0

## Overview

`files_sharding` implements a master/silo architecture:

- One **master** Nextcloud instance acts as the central identity and redirect hub. Users log in at the master URL and are immediately redirected to their assigned silo.
- N **silo** Nextcloud instances each run a full, independent Nextcloud installation. A user's files live entirely on their silo.
- **Federated shares** link silos together using stable `user@master` identities, so users on different silos can share files without knowing which silo the other user is on.
- The master holds the server registry and user-to-silo assignments. Silos report free space back to the master; new users are auto-assigned to a silo by identity regex, falling back to the least-loaded silo (see *Silo selection*).

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
| `files_sharding_logout_url` | Master (optional) | Where the browser lands after logout (e.g. a public welcome page). Default: `/` — note that with user_saml + multiple user backends, an anonymous `/` shows the backend-select page. |
| `files_sharding_hidden_users` | All nodes (optional) | List of uids hidden from people-search (share dialog and group member-add). Meant for service accounts. Example: `['cloud','batch']`. |

### Required appconfig (stock Nextcloud settings the cluster depends on)

```bash
occ config:app:set files_sharing show_federated_shares_to_trusted_servers_as_internal --value=true --type=boolean
```

Load-bearing (stock NC key, set on **every** node): the share dialog's
"Internal shares" box only requests remote share types when this is on — without
it cluster users on other silos cannot appear there at all, on any node. The
cluster nodes must also be in each other's trusted-servers list (Federation).
See [`docs/share-dialog.md`](docs/share-dialog.md).

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

### Silo selection (auto-assignment)

When a user with no silo assignment logs in interactively for the first time, `PostLoginListener` calls `ShardingService::autoAssign()` on the master to pick their home silo, in this priority order:

1. **Admins are skipped.** Members of the `admin` group stay on the master (they need the admin UI) and are never pushed to a silo.
2. If **no silos are registered**, the user stays on the master.
3. **Regex match first.** Each silo may define a `user_regex` (in `files_sharding_servers`). Silos whose regex matches the user ID are the candidates, sorted by free space (`free_gb`, descending); the emptiest match wins. This lets you route users to a silo by identity — e.g. send `@dtu.dk` users to a DTU silo.
4. **Free-space fallback.** If no regex matches, all silos are sorted by free space and the emptiest is chosen.

The choice is stored in `files_sharding_user_servers`, then `getRedirectUrl()` sends the user there. User IDs are WAYF UIDs (`eduPersonPrincipalName`); for some institutions (e.g. DTU) this is the same as the user's email.

Because auto-assignment fires on the login event, users created **without** an interactive login (bulk/scripted provisioning or migration) are *not* assigned automatically — run `occ files-sharding:auto-assign <uid>` for each, or assign explicitly with `occ files-sharding:assign-user <uid> <serverId>`.

### Login redirect flow

1. User logs in at the master.
2. `PostLoginListener` looks up the user's assigned silo, auto-assigning one on first login if the user has none (see *Silo selection* above).
3. Master issues a short-lived token and redirects the browser to `https://silo/index.php/apps/files_sharding/login?token=…`.
4. The silo validates the token with the master (`/internal/token/validate`) and creates a local NC session.

### WebDAV

The desktop sync client and WebDAV clients need the **silo URL**, not the master URL. Nextcloud's own WebDAV client follows the `X-NC-SiloURL` header set on redirect; generic WebDAV clients must be pointed at the silo directly.

### Federation

Users are identified as `user@masterhost` in federation. The master proxies share acceptance and syncs share state to the correct silo via the internal API.

### Sharing model — one authority, derived caches

Sharing is **not** distributed state. The **master is the single authority** and is consulted to *resolve* what a user can access; each silo holds only a **derived cache** that it reconciles against the master. This mirrors the pre-Nextcloud ScienceData design (sharing as a function of a few authoritative tables) and is what keeps silo replacement, user migration, and downtime from being sharing problems: the worst case is staleness until the next reconcile, never permanent drift.

- **Authority (master):** incoming federated shares aggregate in the master's `oc_share_external`; the master's rows are **pull-validated** against the owning silos by `ShareAuthorityReconcileJob` (see `docs/share-lifecycle.md` — the canonical description of the share model, delivery, invariants and DAV surface).
- **Group shares** are delivered by **per-member federated fan-out** (`GroupShareFanoutService`): one real federated child `owner→member@master` per cross-silo member, riding the ordinary OCM path with a proper per-recipient token — no public link, no owner impersonation. Reconcile is stateless and idempotent; membership changes re-reconcile via `GroupMembershipListener`.
- **Cache (silo):** `ShareSyncService` materialises the master's export as `oc_share_external` mounts, **fully reconciled** (add new / prune gone) on every refresh — login, each Files-app load (`SyncExternalSharesListener`), and a master→silo push for immediacy.

**WebDAV surface** — the default endpoint serves the user's OWN data only; shares are reached through dedicated endpoints (old-service model):

| Endpoint | Content |
|---|---|
| `/remote.php/webdav`, `/remote.php/dav/files/{uid}` | own files only for external clients (sync/curl/mounts); received shares + grant folders are concealed for requests with an `Authorization` header — the cookie-authenticated web UI keeps the stock view |
| `/remote.php/sharingin/<owner_id>/<item>` | shares received, one dir per owner, **read/write** per share permissions |
| `/remote.php/sharingout/` | what the user has shared, flat (fan-out children collapsed) |
| `/remote.php/user_group_admin/{gid}/` | grant folders (user_group_admin app) |

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
| `GET` | `/internal/users/{userId}/external-shares` | Resolve share state (direct + group) |
| `POST` | `/internal/users/{userId}/sync-shares` | Sync incoming shares |
| `POST` | `/internal/shares/proxy-accept` | Proxy share acceptance |
| `POST` | `/internal/shares/live-ids` | Liveness batch: which of these share ids does this silo still serve? (master's share-authority reconcile) |
| `POST` | `/internal/users/{userId}/update` | Propagate user changes |
| `POST` | `/internal/users/{userId}/delete` | Propagate user deletion |

## Documentation

- [`docs/share-lifecycle.md`](docs/share-lifecycle.md) — the share model, cross-silo delivery, invariants and the DAV surface.
- [`docs/share-dialog.md`](docs/share-dialog.md) — the share dialog's two search boxes: all cluster users in the Internal box via the canonical `user@master` identity, the collaborator-search plugins, and the appconfig this depends on.
- [`docs/x509-auth.md`](docs/x509-auth.md) — X.509 client-certificate authentication: the `/grid/` endpoint, trusted-daemon impersonation (batch/GridFactory I/O), user-pod certs, the two headers, config keys, and the web-server forgery invariant.

## Development

No build step. Pure PHP + plain JS.

```bash
# Deploy to one node
rsync -av --delete apps/files_sharding/ server:/var/www/nextcloud/apps/files_sharding/

# Run pending migrations after schema changes
occ migrations:execute files_sharding <VersionClass>
```

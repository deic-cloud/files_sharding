# The share dialog's two search boxes on a sharded cluster

NC 34's share panel has two people-search inputs:

- **"Internal shares"** — users, groups, teams … on *this service*.
- **"External shares"** — federated sharing with *other* Nextcloud/OCM servers
  (`user@somewhere-else.org`), plus share-by-email.

## The design decision

**Every cluster user is findable in the INTERNAL box, from every node, exactly
once** — regardless of which silo they live on. The External box is only for
genuine out-of-cluster partners; cluster peers must never appear there (they'd
be a second, redundant way to reach the same person).

Cross-silo entries are, under the hood, federated shares carrying the
**canonical `user@master` identity** (stable across silo reassignments, and the
silo hostname is never shown — silo-invisibility). To the user they simply look
like internal users.

## What makes it work

| Piece | Role |
|---|---|
| `files_sharing` appconfig `show_federated_shares_to_trusted_servers_as_internal=true` | **Load-bearing.** The Internal box only *requests* remote share types when this is set — without it the server-side plugins never even run for that box and cluster peers cannot appear there at all. Seeded by `sciencedata_firstboot.sh`; the trusted-servers list must contain the cluster nodes. |
| `MasterUserSearch` (collaborator-search plugin, `SHARE_TYPE_REMOTE`) | Queries the master directory (`internal/users/search`) live and adds every match whose home silo is *not* this node as a trusted `user@master` remote entry — Internal box only. |
| `ResidentUserFilter` (`SHARE_TYPE_USER`) | On the master the local directory holds an account for *every* cluster user; this strips those dead local (type-0) targets for non-residents, leaving only the federated entry. Also clears core's stale "exact users id match" flag after such a removal — otherwise core's final sanitising step ("exact local match on an email-alike query → drop all remotes") wipes the federated entry and a **full-uid search returns nothing on the master**. |
| `RemoteClusterDedupeFilter` (`SHARE_TYPE_REMOTE`, runs last) | The federation address-book sync makes core offer cluster peers as `user@silo` *and* `user@master`; this removes all variants and re-adds one canonical entry (Internal box only). Skips peers **resident on this node** (core already lists them locally — re-adding was the "shows twice" bug), purges type-0 addressbook artifacts for non-residents, and disarms the users-exact wipe when we hold an exact cluster match (two distinct accounts can legitimately match one query: a local user by *email* and a cross-silo user by *uid*). |

Box detection server-side: the Internal box's sharee request includes
`TYPE_USER` in `shareType[]`; the External box's never does.

Residency on a silo can't come from the master-only user→silo map; the dedupe
filter falls back to "does a local account with **exactly this uid** exist"
(strict, because `IUserManager::get()` also resolves *email addresses* — a local
account whose email equals another cluster user's uid must not shadow them).

## What the user sees (when healthy)

- Internal box, any node: every cluster user, once — locals as plain users,
  cross-silo users as `Display Name (uid)` with no host shown.
- External box: only genuine external addresses (`someone@other-server.org`),
  plus stock artifacts like the literal parse of whatever was typed.
- One query can legitimately yield two people: `fror@dtu.dk` finds the account
  with that **uid** (cross-silo) *and* a local account carrying it as **email**.
  Both are shown; the searcher picks.

## Debugging

Probe the endpoint the boxes call (as any logged-in user):

    # Internal box                                    # External box
    shareType[]=0&shareType[]=1&shareType[]=6&...     shareType[]=4&shareType[]=6&...
    GET /ocs/v2.php/apps/files_sharing/api/v1/sharees?format=json&itemType=file&search=<q>&<box params>

Healthy Internal-box answer for a cross-silo user: one `remotes` (or
`exact.remotes`) entry `{"shareWith":"<uid>@<master-host>","isTrustedServer":true}`.
Untrusted entries in the Internal box's response are filtered out client-side.

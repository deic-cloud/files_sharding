# Cross-silo share lifecycle & why it is eventually-consistent

Status: **decided** (2026-08-23). This documents the architecture we run and the
reasoning behind it, so the trade-offs aren't re-litigated from scratch later.

## Decision

Cross-silo shares are delivered by **per-member federated fan-out with
silo-local mirrors, reconciled to eventual consistency** — *not* by a fully
centralized share table on the master. This note records what that means, why we
rejected centralization, where the consistency actually comes from, and the
backstop that makes it production-safe.

## Why not fully centralized (the old-service model)

The previous ScienceData service kept **all** shares in the master's `oc_share`
as the single source of truth. It worked, but at a real cost that we do not want
to reproduce:

- `oc_share.item_source` and `file_source` both map to `oc_filecache.fileid`, and
  the uid-impersonation approach required the shared files to **actually exist on
  the master**. To make that work across silos, each silo was restricted to a
  **fileid range**, `file_source` on the master held a **dummy file at the same
  path** as the real file on the silo, and the otherwise-unused `item_source` was
  repurposed to carry the silo's real `file_source`. It took a long time to get
  working, leaned on ugly hacks, and it is "sort of a miracle it works as well as
  it does" (Frederik). Years of bug-fixing kept it alive — not something to go
  back to.

Centralization also means: **sharing is unavailable whenever the master is
down**, and every share operation is a round-trip to the master ("chit-chat").
Both were tolerable on the old service, and fail-closed-on-master-down is
arguably *correct* for this architecture — but neither is a reason to accept the
`oc_filecache` coupling hacks again.

Crucial point: **the content path is federated in every possible model.** Storage
is silo-local (shared-nothing — the core design bet), so a recipient on silo B
reading an owner's bytes on silo A *always* needs a cross-silo mount + token.
Centralizing the metadata never removes that. So centralization would buy
simplicity of *bookkeeping only*, at the price of the coupling hacks above — a
bad trade. The federated approach keeps the content path honest (native mounts,
proper per-recipient tokens) and the bookkeeping local.

## The model we run (delivery model A)

A group share from an owner on silo A to members on silos B, C touches three
places that must agree:

```
  [1] OWNER SILO (A)                 [2] MASTER                       [3] RECIPIENT SILO (B)
  ─────────────────                  ──────────                       ─────────────────────
  real group share (oc_share)        authoritative oc_share_external   mirror oc_share_external
  one federated child per     ──OCM──▶  row per member          ──pull──▶  materialised on sync
    remote member (owner→member@master)  exportExternalShares serves it     mount + token → reads
  files_sharding_group_fanout          to the member's silo                 owner's bytes on A
    (tracking table)
```

- Co-resident members (same silo as the owner) keep core's native usergroup
  child; only cross-silo members get a federated child.
- Federated children carry proper **per-recipient tokens** and native mounts — no
  shared public-link secret. (An earlier "derived cache" model used one companion
  link-token per group share; rejected for the owner-impersonation / link-leak
  risk. Its `GroupShareRegistry` + `files_sharding_group_shares` table were retired
  in v1.0.10 — fully dead once model A landed.)

## Where consistency comes from

- **Recipient side is self-healing.** `ShareSyncService::syncForUser` pulls the
  master's authoritative list and both inserts missing mirrors **and prunes any
  local mirror the master no longer lists**. It runs on login, on every
  Files-page load, and on master push. A leaked mirror on a *silo* is swept the
  next time that user opens Files.

- **Master authority is the risk, now backstopped.** The master's rows [2] were
  historically corrected *only* by OCM unshare notifications from the owner
  (`deleteChild → deleteShare`, fire-and-forget). A missed notification (owner
  silo briefly down, a race, churn) left the master row leaked; and because silos
  mirror the master faithfully, the dead mount then persisted everywhere with no
  backstop. That is the fragility a stale-mirror cron crash exposed.

## The backstop (v1.0.10) — make the master's authority *derived*, not *accumulated*

`ShareAuthorityReconcileJob` (`TimedJob`, master-only, every 15 min): for every
cluster-origin `oc_share_external` row it asks the **owning silo, in one batch
call per silo** (`internal/shares/live-ids`), which of those share ids still
exist, and prunes the rows the owner reports absent. Downstream silos then drop
their mirrors on the next `syncForUser`, so the whole chain converges.

- **Not** a per-mirror PROPFIND — that is the per-network-call cost we deliberately
  removed from the fan-out hot path. One batch per owner silo.
- **Fail-safe:** a missing/garbled reply prunes *nothing* for that owner — we only
  ever remove rows the owner explicitly reports absent, so a transient outage
  never deletes a live share.
- **External (non-cluster) remotes are skipped** — we can't cheaply validate a
  foreign server and must not prune those; core's on-access handling covers them.

Invariants:
- **I1.** Every master row [2] corresponds to a live source share [1] (within ≤ the
  15-min job interval).
- **I2.** Every silo mirror [3] ⊆ master rows [2] (via `syncForUser`).
- **I3.** Therefore [3] ⊆ [1] eventually — no dead mount survives a full cycle.

## Supporting hardening (v1.0.10)

- **Null-user cron guard** (core `apps/files_sharing/lib/External/Manager.php`,
  `removeShare`): a dead external mirror must never crash the userless `ScanFiles`
  cron. Core auto-removes a share when the remote answers 404-but-alive, but in
  cron `$this->user` is null and `stripPath()` fatals the whole job
  (`getUID() on null`). The guard returns early with no user; the stale row is
  cleaned later in a user context or by the reconcile job above. Baked into the
  mfsbsd image (`patch_external_manager_nulluser.pl`) since it is a core file.

## The DAV surface: shares live OFF the default endpoint (2026-08-24)

NC's stock policy mounts received shares (and our grant folders) directly in the
user's home, next to their own files. On this platform that is wrong three ways
(Frederik's long-standing grievances, same as the old service): **privacy** — a
group owner sharing research data must be able to assume it does not silently
replicate to every member's laptop via a sync client; **confusion** — unknown
folders appear in the home and get renamed ("(2)" churn) or deleted by puzzled
users; **collaboration model** — we encourage collaborating on a shared *data
directory* (one party uploads, others process/consume), NOT sync-client
co-editing of documents, whose conflict handling is a support nightmare.

The model (old-service, sites/developer docs):

- `HOME_URL/files` (default WebDAV) = the user's OWN data only.
- `/remote.php/sharingin/<owner_id>/<shared item>` — everything shared WITH the
  user, one directory per sharing owner. **Read/write** (the collaboration
  surface); enforcement = each share's permission mask. Backed by BOTH local
  `oc_share` rows and `oc_share_external` mirrors (in this cluster, most shares).
  Owner-grouping also dissolves the name-collision renaming. Syncing this URL is
  possible only as a conscious act (nice-to-have; newer NC desktop clients don't
  follow cross-endpoint redirects, so treat sync support as best-effort).
- `/remote.php/sharingout/` — flat listing of what the user has shared
  (fan-out children collapsed into their group share).
- `/remote.php/user_group_admin/{gid}/` — grant folders (existing endpoint).

Enforcement (`Application::concealSharesFromDavClients`): on the default DAV
endpoints (`/remote.php/webdav`, `/remote.php/dav`) for requests carrying an
`Authorization` header (sync clients, curl, mounted drives — the web UI uses
session cookies and is untouched), a mount filter drops every
`ISharedMountPoint` (local + federated received shares) and a home-storage cache
wrapper conceals `files/.uga_grants` (strips listings AND 404s direct access).
The web Files app keeps its stock view ("All files" / "Shared with you") —
accepted as-is. Consequence to know: NC mobile apps authenticate like sync
clients, so they see own files only — consistent with the old service.

## Accepted residual gaps (documented, not bugs)

- **Dangling storages.** Pruning a mirror row leaves its `oc_storages` /
  `oc_filecache` entries as harmless orphans (no mount → not scanned). Known
  cleanup gap, tracked in BACKLOG; the null-user guard means they can't crash cron.
- **Delivery latency.** Cross-silo members see a new share within ≤ the sync
  cadence (login / Files-load / master push); same-silo members are instant.
- **Master-down.** Fan-out delivery and the reconcile pause while the master is
  down; existing mirrors keep working (local), so reads survive a master blip —
  a modest advantage over the fully-centralized model.

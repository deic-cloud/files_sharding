# X.509 certificate authentication (`X509Backend`)

`OCA\FilesSharding\Auth\X509Backend` lets a request authenticate with a
**verified X.509 client certificate** instead of a password. It is an
`IApacheBackend` — Nextcloud consults it when the web server has already
verified a client certificate and passed the details on as request headers.

This is what powers:

- **the `/grid/` endpoint** (rewritten to `/remote.php/webdav/`), the WebDAV
  surface the **batch / GridFactory** service uses to fetch a job's input files
  and deliver its output files **on behalf of the submitting user**;
- **user pods / containers** authenticating for WebDAV with the user's own
  client certificate (no password);
- **inter-server** X.509 identities (`_server_{id}`), an alternative to the
  shared-secret trust the cluster normally uses.

## The two headers

The backend reads **two different** request headers. Keeping them straight is
the whole point:

| Header | Config key | Carries | Set by |
|--------|-----------|---------|--------|
| `SSL-CLIENT-S-DN` (fallback `X-Ssl-Client-S-Dn`) | — (fixed) | the **subject DN of the presented, web-server-verified client certificate** — *who is connecting* | the front web server / the trusted client (see security note) |
| `SSL-CLIENT-DN` | `dn_header` (default `SSL-CLIENT-DN`) | the **user to act as** — a bare username or a subject DN — *whom to impersonate* | **only** a trusted daemon |

## Resolution order (`getCurrentUserId`)

Given the presented DN `D = SSL-CLIENT-S-DN`:

1. **Trusted daemon** — if `D` is one of `trusted_dn_header_host_dns`
   (comma-separated, slash-format DNs; e.g. `/CN=batch,/CN=batch/O=sciencedata.dk`),
   the request may act on behalf of **any** user. The target is read from the
   `dn_header` header (`SSL-CLIENT-DN`) and resolved to an existing account by
   exact username, then the CN of a DN, then a DN a user has registered.
   This is the batch / GridFactory I/O path.
2. **Registered server** — if `D` matches a silo's stored `x509_dn`, the
   identity is the synthetic `_server_{id}` (never listed; inter-server only).
3. **A user's own certificate** — if `D` matches a DN a user registered on the
   personal X.509 settings page (stored in `oc_preferences`,
   `files_sharding` / `x509_dn_0..9`, compared tokenised so slash- vs
   comma-format and attribute order don't matter), the identity is that user.
   An ambiguous DN (registered by more than one user) is **refused**.

DNs are compared **tokenised** (`CN=…,O=…` ⇔ `/CN=…/O=…`, order-independent).

## Configuration

| Key | Where | Meaning |
|-----|-------|---------|
| `my_ca_certificate` / `my_ca_privatekey` | `sciencedata.config.php` | the **ScienceData CA** that signs the client certs `CertificateService` issues (`/CN=<user>/O=sciencedata.dk`). Must be the CA the web server trusts as `SSLCACertificateFile` — **not** the Let's Encrypt host cert. (LE dropped client-auth support; server↔server certs moved to this CA. A stale config copy once pointed these at the LE host cert `cert.pem`, which is a leaf, not a CA — client certs then failed verification.) |
| `trusted_dn_header_host_dns` | `sciencedata.config.php` | comma-separated slash-format DNs allowed to impersonate (e.g. the batch daemon `/CN=batch`). |
| `dn_header` | `sciencedata.config.php` | the header naming the impersonation target (default `SSL-CLIENT-DN`). |
| `x509_dn_0..9` | `oc_preferences` per user | DNs a user has registered for their own certificate auth (personal settings). |

## Web-server configuration (security-critical)

The web server in front of Nextcloud MUST verify the client certificate against
the ScienceData CA (`SSLCACertificateFile` = the CA matching `my_ca_certificate`)
and expose the details as the headers above. On the ScienceData Apache the
`/grid/` (and `ws/`) locations carry `SSLVerifyClient optional` +
`SSLOptions +StdEnvVars`, and `/grid/` is rewritten to `/remote.php/webdav/`.

**Forgery invariant.** Because impersonation is header-driven, the front proxy
is the trust boundary: it must guarantee that a client which has **not**
presented a certificate whose DN is in `trusted_dn_header_host_dns` cannot set
the `SSL-CLIENT-S-DN` / `SSL-CLIENT-DN` headers itself — otherwise anyone could
claim to be the batch daemon and read any user's files. The exact directives
that enforce this (which header is set from the verified cert env var
`%{SSL_CLIENT_S_DN}s`, which is stripped from untrusted connections, and how the
trusted daemon supplies the impersonation target) are part of the **deployment
Apache config / image**, not this app, and are documented for the production
service in the scienceteam docs. Keep those two sources in sync.

> Note: a plain user presenting their **own** certificate directly to `/grid/`
> is use-case (3) and only completes if the verified DN reaches PHP as
> `SSL-CLIENT-S-DN`; the primary production path is the trusted-daemon
> impersonation (1), which the batch service drives.

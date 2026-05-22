<?php
/** @var OCP\IL10N $l */
/** @var array $_ */
?>
<div id="fsh-admin" class="section">
<!-- ── Servers ─────────────────────────────────────────────────────── -->
<h3>Silo servers</h3>
<table id="fsh-servers" style="width:100%;border-collapse:collapse;margin-bottom:1em">
 <thead>
  <tr>
   <th>URL</th><th>Internal URL</th><th>X.509 DN</th><th>Site</th>
   <th>User regex</th><th>Free (GB)</th><th></th>
  </tr>
 </thead>
 <tbody></tbody>
</table>

<details style="margin-bottom:1.5em">
 <summary style="cursor:pointer;font-weight:bold">Add / edit server</summary>
 <form id="fsh-server-form" style="display:grid;grid-template-columns:1fr 1fr;gap:.5em 1em;margin-top:.5em;max-width:700px">
  <input type="hidden" id="fsh-server-id" value="">
  <label>URL<br><input type="url" id="fsh-s-url" placeholder="https://silo.example.org" style="width:100%"></label>
  <label>Internal URL (optional)<br><input type="url" id="fsh-s-iurl" placeholder="http://10.0.0.2" style="width:100%"></label>
  <label>X.509 DN (optional)<br><input type="text" id="fsh-s-dn" placeholder="CN=silo,O=Example" style="width:100%"></label>
  <label>Site (optional)<br><input type="text" id="fsh-s-site" placeholder="Copenhagen" style="width:100%"></label>
  <label style="grid-column:1/-1">Description (optional)<br><input type="text" id="fsh-s-desc" placeholder="Primary Copenhagen silo" style="width:100%"></label>
  <label style="grid-column:1/-1">
   User regex (optional PCRE, e.g. <code>/@sdu\.dk$/</code>)<br>
   <input type="text" id="fsh-s-regex" placeholder="/@example\.org$/" style="width:100%;font-family:monospace">
   <small>When set, new users whose ID matches are auto-assigned here (falls back to free-space if no silo matches).</small>
  </label>
  <div style="grid-column:1/-1">
   <button type="submit" class="primary">Save server</button>
   <button type="button" id="fsh-server-cancel" style="margin-left:.5em">Cancel</button>
  </div>
 </form>
</details>

<!-- ── User assignment overview ─────────────────────────────────────── -->
<h3>User → silo assignments</h3>
<div style="display:flex;gap:.5em;align-items:center;margin-bottom:.5em;flex-wrap:wrap">
 <label>Filter by silo:
  <select id="fsh-list-server" style="width:220px">
   <option value="0">All silos</option>
  </select>
 </label>
 <button id="fsh-list-reload" type="button">Reload</button>
 <span id="fsh-list-info" style="color:var(--color-text-maxcontrast,#888);font-size:.9em"></span>
</div>
<table id="fsh-users" style="width:100%;border-collapse:collapse;margin-bottom:.5em">
 <thead>
  <tr><th>User ID</th><th>Silo</th><th>Site</th><th>Access</th><th></th></tr>
 </thead>
 <tbody><tr><td colspan="5" style="text-align:center;padding:.5em;color:var(--color-text-maxcontrast,#888)">Loading…</td></tr></tbody>
</table>
<div id="fsh-list-pages" style="display:flex;gap:.3em;margin-bottom:1.5em"></div>

<!-- ── Assign / move a user ─────────────────────────────────────────── -->
<h3>Assign / move a user</h3>
<div style="display:flex;gap:.5em;align-items:flex-end;flex-wrap:wrap;margin-bottom:.5em">
 <label>User ID<br><input type="text" id="fsh-user-id" placeholder="alice@sdu.dk" style="width:220px"></label>
 <label>Silo<br>
  <select id="fsh-user-server" style="width:260px">
   <option value="">(unassigned)</option>
  </select>
 </label>
 <label>Access<br>
  <select id="fsh-user-access">
   <option value="0">Read-write</option>
   <option value="1">Read-only</option>
  </select>
 </label>
 <button id="fsh-user-lookup" type="button">Look up</button>
 <button id="fsh-user-save" type="button" class="primary" disabled>Save</button>
</div>
<p id="fsh-user-msg" style="color:var(--color-error,red);min-height:1.2em"></p>

<!-- ── Regex tester ─────────────────────────────────────────────────── -->
<h3>Auto-assignment preview</h3>
<p class="settings-hint">Enter a user ID to see which silo the auto-assign logic would pick.</p>
<div style="display:flex;gap:.5em;align-items:flex-end;flex-wrap:wrap;margin-bottom:.5em">
 <label>User ID<br><input type="text" id="fsh-test-uid" placeholder="alice@sdu.dk" style="width:220px"></label>
 <button id="fsh-test-btn" type="button">Preview assignment</button>
</div>
<p id="fsh-test-result" style="min-height:1.2em;font-style:italic"></p>

<p id="fsh-admin-status" style="color:var(--color-error,red);min-height:1.2em"></p>
</div>

<style>
#fsh-servers th, #fsh-servers td,
#fsh-users th, #fsh-users td { padding:.3em .6em; border-bottom:1px solid var(--color-border,#ddd); text-align:left; vertical-align:top; }
#fsh-servers th, #fsh-users th { font-weight:bold; background:var(--color-background-dark,#f4f4f4); }
#fsh-servers .fsh-regex { font-family:monospace; font-size:.85em; word-break:break-all; color:var(--color-text-maxcontrast,#555); }
#fsh-list-pages button { min-width:2em; }
</style>

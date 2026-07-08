<?php
/** @var OCP\IL10N $l */
/** @var array $_ */
$isMaster        = $_['is_master']         ?? false;
$masterUrl       = $_['master_url']        ?? '';
$sudoInitiateUrl = $_['sudo_initiate_url'] ?? '';
?>
<div id="fsh-personal" class="section">
<!-- ── Folder visibility rules ─────────────────────────────────────── -->
<h3>Folder visibility rules</h3>
<p class="settings-hint">Restrict access to specific folders based on client IP address or client type.
Leave <em>Allowed from</em> empty to allow from any IP.</p>

<table id="fsh-folders" style="width:100%;border-collapse:collapse;margin-bottom:.8em">
 <thead>
  <tr>
   <th>Folder</th>
   <th>Allowed from (CIDR, comma-separated)</th>
   <th>Hide from sync clients</th>
   <th></th>
  </tr>
 </thead>
 <tbody></tbody>
</table>

<form id="fsh-folder-form" style="display:flex;gap:.5em;align-items:flex-end;flex-wrap:wrap;margin-bottom:1.5em">
 <label>Folder path<br><input type="text" id="fsh-f-folder" placeholder="/Documents" style="width:180px"></label>
 <label>Allowed from<br><input type="text" id="fsh-f-from" placeholder="10.0.0.0/8, 192.168.1.0/24" style="width:260px"></label>
 <label style="align-self:center;padding-top:1.2em"><input type="checkbox" id="fsh-f-hide"> Hide from sync clients</label>
 <button type="submit" class="primary">Add rule</button>
</form>

<!-- ── X.509 client certificates ───────────────────────────────────── -->
<h3>X.509 client certificate DNs</h3>

<ul id="fsh-dns" style="margin-bottom:.8em;padding:0;list-style:none"></ul>

<form id="fsh-dn-form" style="display:flex;gap:.5em;align-items:flex-end;flex-wrap:wrap;margin-bottom:1em">
 <label>Subject DN<br>
  <input type="text" id="fsh-dn-value" placeholder="CN=Alice,O=Example,C=DK" style="width:340px">
 </label>
 <button type="submit" class="primary">Add DN</button>
</form>

<!-- ── Certificate generation ──────────────────────────────────────── -->
<h3>Personal certificate</h3>
<p class="settings-hint">Generate a personal RSA-4096 certificate signed by the deployment CA.
Use it for passwordless WebDAV login (add its DN above) or HTTPS client authentication.</p>

<div id="fsh-cert-info" style="margin-bottom:.8em"></div>

<div style="display:flex;gap:.5em;align-items:flex-end;flex-wrap:wrap;margin-bottom:1em">
 <label>Validity (days)<br><input type="number" id="fsh-cert-days" value="365" min="1" max="3650" style="width:100px"></label>
 <button id="fsh-cert-generate" class="primary" type="button">Generate certificate</button>
 <button id="fsh-cert-delete" type="button" style="display:none">Delete certificate &amp; key</button>
</div>

<p id="fsh-personal-status" style="color:var(--color-error,red);min-height:1.2em"></p>

<!-- ── Re-authenticate via master ──────────────────────────────────── -->
<?php if ($sudoInitiateUrl !== ''): ?>
<h3>Re-authenticate via master</h3>
<p class="settings-hint">
  Some security settings (app passwords, active sessions) require re-authentication.
  Because your account uses federated login, click the button below to
  re-authenticate via the master server. This opens a 30-minute window.
</p>
<p>Status: <span id="fsh-sudo-status" style="color:var(--color-text-maxcontrast,#888)">Checking…</span></p>
<p style="margin-top:.4em">
  <a id="fsh-sudo-btn" href="<?php p($sudoInitiateUrl); ?>"
     class="button primary">Confirm identity via master</a>
</p>
<?php endif; ?>
</div>

<style>
#fsh-folders th, #fsh-folders td { padding:.3em .6em; border-bottom:1px solid var(--color-border,#ddd); text-align:left; }
#fsh-folders th { font-weight:bold; background:var(--color-background-dark,#f4f4f4); }
#fsh-folders td.fsh-hide-cell { text-align:center; }
#fsh-dns li { padding:.3em 0; border-bottom:1px solid var(--color-border,#ddd); display:flex; align-items:center; gap:.5em; }
#fsh-dns li code { flex:1; font-size:.9em; word-break:break-all; }
#fsh-cert-info dl { display:grid; grid-template-columns:max-content 1fr; gap:.2em .8em; margin:0; }
#fsh-cert-info dt { font-weight:bold; }
#fsh-cert-info .fsh-cert-downloads { margin-top:.4em; display:flex; gap:.5em; }
</style>

(function() {
'use strict';
const token = () => OC.requestToken;
const base  = '/ocs/v2.php/apps/files_sharding/api/v1';
const status = (msg, ok) => {
  const el = document.getElementById('fsh-personal-status');
  el.textContent = msg;
  el.style.color = ok ? 'var(--color-success,green)' : 'var(--color-error,red)';
};

async function ocsCall(method, path, body) {
  const opts = {
    method,
    headers: { 'OCS-APIREQUEST': 'true', 'requesttoken': token(), 'Content-Type': 'application/json' },
  };
  if (body) opts.body = JSON.stringify(body);
  const r = await fetch(base + path + (path.includes('?') ? '&' : '?') + 'format=json', opts);
  return r.json();
}

function e(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ── Folder rules ──────────────────────────────────────────────────────────────

async function loadFolders() {
  const d = await ocsCall('GET', '/folders');
  const folders = d.ocs?.data?.folders || [];
  const tb = document.querySelector('#fsh-folders tbody');
  tb.innerHTML = '';
  folders.forEach(f => {
    const tr = document.createElement('tr');
    const checked = f.hide_from_clients ? 'checked' : '';
    tr.innerHTML = `
      <td><code>${e(f.folder)}</code></td>
      <td>${e(f.only_from || '(any)')}</td>
      <td class="fsh-hide-cell"><input type="checkbox" ${checked} data-id="${f.id}" class="fsh-hide-toggle" title="Hide from sync clients (mirall / ownCloud desktop)"></td>
      <td><button data-id="${f.id}" class="fsh-del-folder" type="button" title="Remove">🗑️</button></td>`;
    tb.appendChild(tr);
  });
}

document.getElementById('fsh-folders').addEventListener('change', async ev => {
  const cb = ev.target.closest('.fsh-hide-toggle');
  if (!cb) return;
  const r = await ocsCall('PUT', '/folders/' + cb.dataset.id, { hideFromClients: cb.checked });
  if (r.ocs?.meta?.status !== 'ok') {
    cb.checked = !cb.checked; // revert on failure
    status(r.ocs?.meta?.message || 'Error', false);
  }
});

document.getElementById('fsh-folders').addEventListener('click', async ev => {
  const btn = ev.target.closest('.fsh-del-folder');
  if (!btn) return;
  const r = await ocsCall('DELETE', '/folders/' + btn.dataset.id);
  if (r.ocs?.meta?.status === 'ok') { await loadFolders(); status('Removed.', true); }
  else status(r.ocs?.meta?.message || 'Error', false);
});

document.getElementById('fsh-folder-form').addEventListener('submit', async ev => {
  ev.preventDefault();
  const folder          = document.getElementById('fsh-f-folder').value.trim();
  const onlyFrom        = document.getElementById('fsh-f-from').value.trim();
  const hideFromClients = document.getElementById('fsh-f-hide').checked;
  if (!folder) { status('Folder path is required', false); return; }
  const r = await ocsCall('POST', '/folders', { folder, onlyFrom, hideFromClients });
  if (r.ocs?.meta?.status === 'ok') {
    document.getElementById('fsh-f-folder').value = '';
    document.getElementById('fsh-f-from').value   = '';
    document.getElementById('fsh-f-hide').checked = false;
    await loadFolders();
    status('Rule added.', true);
  } else {
    status(r.ocs?.meta?.message || 'Error', false);
  }
});

// ── X.509 DNs ─────────────────────────────────────────────────────────────────

async function loadDns() {
  const d = await ocsCall('GET', '/x509');
  const dns = d.ocs?.data?.dns || [];
  const ul = document.getElementById('fsh-dns');
  ul.innerHTML = '';
  if (dns.length === 0) {
    ul.innerHTML = '<li style="color:var(--color-text-maxcontrast,#888)">No DNs stored.</li>';
    return;
  }
  dns.forEach(entry => {
    const li = document.createElement('li');
    li.innerHTML = '<code>' + e(entry.dn) + '</code>'
      + '<button data-index="' + entry.index + '" class="fsh-del-dn" type="button" title="Remove">🗑️</button>';
    ul.appendChild(li);
  });
}

document.getElementById('fsh-dns').addEventListener('click', async ev => {
  const btn = ev.target.closest('.fsh-del-dn');
  if (!btn) return;
  const r = await ocsCall('DELETE', '/x509/' + btn.dataset.index);
  if (r.ocs?.meta?.status === 'ok') { await loadDns(); status('Removed.', true); }
  else status(r.ocs?.meta?.message || 'Error', false);
});

document.getElementById('fsh-dn-form').addEventListener('submit', async ev => {
  ev.preventDefault();
  const dn = document.getElementById('fsh-dn-value').value.trim();
  if (!dn) { status('DN is required', false); return; }
  const r = await ocsCall('POST', '/x509', { dn });
  if (r.ocs?.meta?.status === 'ok') {
    document.getElementById('fsh-dn-value').value = '';
    await loadDns();
    status('DN added.', true);
  } else {
    status(r.ocs?.meta?.message || 'Error', false);
  }
});

// ── Certificate generation ─────────────────────────────────────────────────────

const certBase = OC.generateUrl('/apps/files_sharding');

function renderCertInfo(info) {
  const div = document.getElementById('fsh-cert-info');
  const del = document.getElementById('fsh-cert-delete');
  if (!info || !info.exists) {
    div.innerHTML = '<p style="color:var(--color-text-maxcontrast,#888)">No certificate generated yet.</p>';
    del.style.display = 'none';
    return;
  }
  div.innerHTML = `
    <dl>
      <dt>Subject</dt><dd><code>${e(info.dn)}</code></dd>
      <dt>Expires</dt><dd>${e(info.expires)}</dd>
    </dl>
    <div class="fsh-cert-downloads">
      <a href="${certBase}/x509/cert" download="usercert.pem">Download certificate (PEM)</a>
      <a href="${certBase}/x509/key"  download="userkey.pem">Download private key (PEM)</a>
      <a href="${certBase}/x509/pkcs12" download="usercert.p12">Download PKCS#12</a>
    </div>`;
  del.style.display = '';
}

async function loadCertInfo() {
  const d = await ocsCall('GET', '/x509/certinfo');
  renderCertInfo(d.ocs?.data || null);
}

document.getElementById('fsh-cert-generate').addEventListener('click', async () => {
  const days = parseInt(document.getElementById('fsh-cert-days').value, 10) || 365;
  const btn = document.getElementById('fsh-cert-generate');
  btn.disabled = true;
  btn.textContent = 'Generating…';
  const r = await ocsCall('POST', '/x509/generate', { days });
  btn.disabled = false;
  btn.textContent = 'Generate certificate';
  if (r.ocs?.meta?.status === 'ok') {
    renderCertInfo(Object.assign({ exists: true }, r.ocs.data));
    status('Certificate generated.', true);
  } else {
    status(r.ocs?.meta?.message || 'Certificate generation failed', false);
  }
});

document.getElementById('fsh-cert-delete').addEventListener('click', async () => {
  if (!confirm('Delete your certificate and private key? This cannot be undone.')) return;
  const r = await ocsCall('DELETE', '/x509/certkey');
  if (r.ocs?.meta?.status === 'ok') {
    renderCertInfo(null);
    status('Certificate deleted.', true);
  } else {
    status(r.ocs?.meta?.message || 'Error', false);
  }
});

// ── Identity confirmation (sudo) ───────────────────────────────────────────

const sudoStatusEl = document.getElementById('fsh-sudo-status');

let sudoCachedToken = sessionStorage.getItem('fsh_sudo_token') || '';

async function loadSudoStatus() {
  if (!sudoStatusEl) return;
  const d = await ocsCall('GET', '/sudo/status');
  const confirmed = d.ocs?.data?.confirmed;
  const expiresIn = d.ocs?.data?.expires_in || 0;
  if (confirmed) {
    const mins = Math.ceil(expiresIn / 60);
    sudoStatusEl.textContent = `Confirmed (expires in ${mins} min)`;
    sudoStatusEl.style.color = 'var(--color-success,green)';
    await fetchSudoToken();
  } else {
    sudoStatusEl.textContent = 'Not confirmed.';
    sudoStatusEl.style.color = 'var(--color-text-maxcontrast,#888)';
    sudoCachedToken = '';
    sessionStorage.removeItem('fsh_sudo_token');
  }
}

async function fetchSudoToken() {
  if (sudoCachedToken) return;
  const d = await ocsCall('GET', '/sudo/token');
  const t = d.ocs?.data?.token || '';
  if (t) {
    sudoCachedToken = t;
    sessionStorage.setItem('fsh_sudo_token', t);
  }
}


loadFolders();
loadDns();
loadCertInfo();
if (sudoStatusEl) loadSudoStatus();
})();

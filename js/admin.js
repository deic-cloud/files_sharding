(function() {
'use strict';
const token = () => OC.requestToken;
const base  = '/ocs/v2.php/apps/files_sharding/api/v1';
const status = (msg, ok) => {
  const el = document.getElementById('fsh-admin-status');
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

// ── Servers ───────────────────────────────────────────────────────────────────

let servers = [];

function renderServers() {
  const tb = document.querySelector('#fsh-servers tbody');
  tb.innerHTML = '';
  servers.forEach(s => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><a href="${e(s.url)}" target="_blank">${e(s.url)}</a></td>
      <td>${e(s.internal_url||'')}</td>
      <td style="font-size:.85em;word-break:break-all">${e(s.x509_dn||'')}</td>
      <td>${e(s.site||'')}</td>
      <td class="fsh-regex">${s.user_regex ? e(s.user_regex) : '<span style="color:var(--color-text-maxcontrast,#aaa)">—</span>'}</td>
      <td>${s.free_gb != null ? s.free_gb : '—'}</td>
      <td>
        <button data-id="${s.id}" class="fsh-edit-server" title="Edit" type="button">✏️</button>
        <button data-id="${s.id}" class="fsh-del-server" title="Delete" type="button">🗑️</button>
      </td>`;
    tb.appendChild(tr);
  });
  // Populate assign-form dropdown
  const sel = document.getElementById('fsh-user-server');
  const cur = sel.value;
  while (sel.options.length > 1) sel.remove(1);
  servers.forEach(s => {
    const o = new Option(s.url + (s.site ? ' (' + s.site + ')' : ''), s.id);
    sel.add(o);
  });
  sel.value = cur;
  // Populate list filter dropdown
  populateListFilter();
}

async function loadServers() {
  const d = await ocsCall('GET', '/servers');
  servers = d.ocs?.data?.servers || [];
  renderServers();
}

document.getElementById('fsh-servers').addEventListener('click', async ev => {
  const btn = ev.target.closest('button');
  if (!btn) return;
  const id = btn.dataset.id;
  if (btn.classList.contains('fsh-edit-server')) {
    const s = servers.find(x => x.id == id);
    if (!s) return;
    document.getElementById('fsh-server-id').value   = s.id;
    document.getElementById('fsh-s-url').value        = s.url;
    document.getElementById('fsh-s-iurl').value       = s.internal_url || '';
    document.getElementById('fsh-s-dn').value         = s.x509_dn || '';
    document.getElementById('fsh-s-site').value       = s.site || '';
    document.getElementById('fsh-s-desc').value       = s.description || '';
    document.getElementById('fsh-s-regex').value      = s.user_regex || '';
    document.querySelector('#fsh-admin details').open = true;
  } else if (btn.classList.contains('fsh-del-server')) {
    if (!confirm('Delete this server?')) return;
    const r = await ocsCall('DELETE', '/servers/' + id);
    if (r.ocs?.meta?.status === 'ok') { await loadServers(); status('Deleted.', true); }
    else status(r.ocs?.meta?.message || 'Error', false);
  }
});

document.getElementById('fsh-server-form').addEventListener('submit', async ev => {
  ev.preventDefault();
  const id    = document.getElementById('fsh-server-id').value;
  const url   = document.getElementById('fsh-s-url').value.trim();
  const regex = document.getElementById('fsh-s-regex').value.trim();
  if (!url) { status('URL is required', false); return; }
  const body = {
    url,
    internalUrl: document.getElementById('fsh-s-iurl').value.trim(),
    x509Dn:      document.getElementById('fsh-s-dn').value.trim(),
    site:        document.getElementById('fsh-s-site').value.trim(),
    description: document.getElementById('fsh-s-desc').value.trim(),
    userRegex:   regex,
  };
  const r = id
    ? await ocsCall('PUT',  '/servers/' + id, body)
    : await ocsCall('POST', '/servers',        body);
  if (r.ocs?.meta?.status === 'ok') {
    document.getElementById('fsh-server-form').reset();
    document.getElementById('fsh-server-id').value = '';
    document.querySelector('#fsh-admin details').open = false;
    await loadServers();
    status('Saved.', true);
  } else {
    status(r.ocs?.meta?.message || 'Error', false);
  }
});

document.getElementById('fsh-server-cancel').addEventListener('click', () => {
  document.getElementById('fsh-server-form').reset();
  document.getElementById('fsh-server-id').value = '';
  document.querySelector('#fsh-admin details').open = false;
});

// ── User list ─────────────────────────────────────────────────────────────────

const PAGE_SIZE = 50;
let listOffset  = 0;
let listTotal   = 0;

function populateListFilter() {
  const sel = document.getElementById('fsh-list-server');
  const cur = sel.value;
  while (sel.options.length > 1) sel.remove(1);
  servers.forEach(s => {
    sel.add(new Option(s.url + (s.site ? ' (' + s.site + ')' : ''), s.id));
  });
  sel.value = cur;
}

async function loadUserList(offset = 0) {
  listOffset = offset;
  const sid = parseInt(document.getElementById('fsh-list-server').value, 10) || 0;
  const params = new URLSearchParams({ limit: PAGE_SIZE, offset });
  if (sid > 0) params.set('server_id', sid);
  const d = await ocsCall('GET', '/users?' + params);
  const assignments = d.ocs?.data?.assignments || [];
  listTotal = d.ocs?.data?.total ?? 0;

  const tb = document.querySelector('#fsh-users tbody');
  tb.innerHTML = '';
  if (assignments.length === 0) {
    tb.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:.5em;color:var(--color-text-maxcontrast,#888)">No assignments found.</td></tr>';
  } else {
    assignments.forEach(a => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${e(a.user_id)}</td>
        <td>${e(a.server_url||'')}</td>
        <td>${e(a.site||'')}</td>
        <td>${a.access === 1 ? 'read-only' : 'read-write'}</td>
        <td></td>`;
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.title = 'Edit assignment';
      btn.textContent = '✏️';
      btn.addEventListener('click', () => {
        const uid = document.getElementById('fsh-user-id');
        uid.value = a.user_id;
        document.getElementById('fsh-user-server').value    = String(a.server_id);
        document.getElementById('fsh-user-access').value    = String(a.access);
        document.getElementById('fsh-user-save').disabled   = false;
        const msg = document.getElementById('fsh-user-msg');
        msg.style.color = 'var(--color-main-text,#000)';
        msg.textContent = 'Editing ' + a.user_id;
        uid.focus();
      });
      tr.querySelector('td:last-child').appendChild(btn);
      tb.appendChild(tr);
    });
  }

  document.getElementById('fsh-list-info').textContent =
    `${offset + 1}–${offset + assignments.length} of ${listTotal}`;
  renderListPages();
}

function renderListPages() {
  const container = document.getElementById('fsh-list-pages');
  container.innerHTML = '';
  if (listTotal <= PAGE_SIZE) return;
  const pages = Math.ceil(listTotal / PAGE_SIZE);
  const cur   = Math.floor(listOffset / PAGE_SIZE);
  for (let i = 0; i < pages; i++) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = i + 1;
    if (i === cur) btn.style.fontWeight = 'bold';
    btn.addEventListener('click', () => loadUserList(i * PAGE_SIZE));
    container.appendChild(btn);
  }
}


document.getElementById('fsh-list-server').addEventListener('change', () => loadUserList(0));
document.getElementById('fsh-list-reload').addEventListener('click',  () => loadUserList(0));

// ── Assign / move a user ──────────────────────────────────────────────────────

document.getElementById('fsh-user-lookup').addEventListener('click', async () => {
  const uid = document.getElementById('fsh-user-id').value.trim();
  const msg = document.getElementById('fsh-user-msg');
  if (!uid) { msg.textContent = 'Enter a user ID'; return; }
  msg.textContent = '';
  const r = await ocsCall('GET', '/users/' + encodeURIComponent(uid) + '/server');
  const sid = r.ocs?.data?.server?.id;
  document.getElementById('fsh-user-server').value = sid ?? '';
  const ra = await ocsCall('GET', '/users/' + encodeURIComponent(uid) + '/access');
  document.getElementById('fsh-user-access').value = ra.ocs?.data?.access ?? 0;
  document.getElementById('fsh-user-save').disabled = false;
  msg.style.color = 'var(--color-main-text,#000)';
  msg.textContent = sid
    ? 'Currently assigned to: ' + (servers.find(s => s.id == sid)?.url ?? 'server ' + sid)
    : 'No silo assigned yet';
});

document.getElementById('fsh-user-save').addEventListener('click', async () => {
  const uid      = document.getElementById('fsh-user-id').value.trim();
  const serverId = parseInt(document.getElementById('fsh-user-server').value, 10);
  const access   = parseInt(document.getElementById('fsh-user-access').value, 10);
  const msg      = document.getElementById('fsh-user-msg');
  if (!uid || !serverId) { msg.textContent = 'Fill in user ID and silo'; return; }
  const r = await ocsCall('PUT', '/users/' + encodeURIComponent(uid) + '/server',
    { serverId, access });
  msg.style.color = r.ocs?.meta?.status === 'ok'
    ? 'var(--color-success,green)' : 'var(--color-error,red)';
  msg.textContent = r.ocs?.meta?.status === 'ok' ? 'Saved.' : (r.ocs?.meta?.message || 'Error');
  if (r.ocs?.meta?.status === 'ok') loadUserList(listOffset);
});

// ── Auto-assignment preview (client-side simulation) ─────────────────────────

document.getElementById('fsh-test-btn').addEventListener('click', () => {
  const uid = document.getElementById('fsh-test-uid').value.trim();
  const out = document.getElementById('fsh-test-result');
  if (!uid) { out.textContent = 'Enter a user ID first.'; return; }
  if (servers.length === 0) { out.textContent = 'No silos registered.'; return; }

  const byFreeDesc = (a, b) => (b.free_gb ?? 0) - (a.free_gb ?? 0);

  const matched = servers.filter(s => {
    const rx = (s.user_regex || '').trim();
    if (!rx) return false;
    try {
      const m = rx.match(/^\/(.*)\/([gimsuy]*)$/s);
      if (!m) return false;
      return new RegExp(m[1], m[2]).test(uid);
    } catch { return false; }
  });

  let chosen, reason;
  if (matched.length > 0) {
    matched.sort(byFreeDesc);
    chosen = matched[0];
    reason = 'regex match (' + e(chosen.user_regex) + ')';
  } else {
    const sorted = [...servers].sort(byFreeDesc);
    chosen = sorted[0];
    reason = 'no regex matched — picked by free space';
  }

  out.innerHTML = 'Would assign to <strong>' + e(chosen.url) + '</strong> — ' + reason;
  out.style.color = 'var(--color-main-text,inherit)';
});

loadServers().then(() => loadUserList(0));
})();

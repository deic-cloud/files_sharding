/**
 * Public-link creation popup (old-service parity), hooked onto the STOCK
 * sharing tab: clicking the sharing pane's "+" (create share link — class
 * .new-share-link, locale-independent) is intercepted and replaced by one
 * dialog that:
 *   1. shows the confidential-data warning (text configurable via system
 *      config files_sharding_link_warning; neutral default) with Cancel/Accept;
 *   2. on accept creates the link share and shows the FULL URL with the last
 *      segment as an editable input (backed by /api/v1/link-name), an
 *      open-in-new-tab link, a copy button, and — when files_picocms is
 *      enabled — a "List as public dataset" checkbox (catalog_listed).
 *
 * Graceful degradation by design: if an NC update reshapes the button, the
 * capture listener simply never matches and the stock flow runs untouched.
 */
(function () {
	'use strict'

	function ocs(method, url, body) {
		return fetch(OC.getRootPath() + url + (url.indexOf('?') === -1 ? '?' : '&') + 'format=json', {
			method: method,
			headers: {
				'OCS-APIREQUEST': 'true',
				'requesttoken': OC.requestToken,
				'Content-Type': 'application/json',
			},
			body: body ? JSON.stringify(body) : undefined,
		}).then(function (r) { return r.json() })
	}

	function warningText() {
		try {
			var s = OCP.InitialState.loadState('files_sharding', 'link_warning')
			if (s) { return s }
		} catch (e) { /* fall through */ }
		return t('files_sharding', 'Sharing data with un-authenticated users is generally not appropriate for confidential and/or personally sensitive data. By creating a public link you confirm that you are permitted to make this data publicly available, and you accept responsibility for it. Do you wish to proceed?')
	}

	function el(tag, attrs, children) {
		var e = document.createElement(tag)
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'style') { e.style.cssText = attrs[k] } else if (k === 'text') { e.textContent = attrs[k] } else { e.setAttribute(k, attrs[k]) }
		})
		;(children || []).forEach(function (c) { e.appendChild(c) })
		return e
	}

	var OVERLAY_STYLE = 'position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:10000;display:flex;align-items:center;justify-content:center;'
	var BOX_STYLE = 'background:var(--color-main-background,#fff);color:var(--color-main-text,#222);border-radius:var(--border-radius-large,10px);max-width:520px;width:92%;padding:20px 24px;box-shadow:0 2px 24px rgba(0,0,0,.4);font-size:14px;'
	var BTN = 'margin:0 4px;padding:8px 16px;border-radius:var(--border-radius-pill,20px);border:1px solid var(--color-border-dark,#ccc);background:var(--color-main-background,#fff);color:inherit;cursor:pointer;'
	var BTN_PRIMARY = BTN + 'background:var(--color-primary-element,#0082c9);color:var(--color-primary-element-text,#fff);border-color:transparent;'

	function sidebarStore() {
		// NC34+: the sidebar is a Pinia store behind OCA.Files._sidebar()
		// (OCA.Files.Sidebar is gone — apps/files/src/sidebar.ts).
		try { return (window.OCA && OCA.Files && OCA.Files._sidebar) ? OCA.Files._sidebar() : null } catch (e) { return null }
	}

	function currentSidebarPath() {
		try {
			var store = sidebarStore()
			if (store && store.currentNode && store.currentNode.path) {
				return store.currentNode.path
			}
			// pre-NC34 fallback
			if (window.OCA && OCA.Files && OCA.Files.Sidebar && OCA.Files.Sidebar.file) {
				return OCA.Files.Sidebar.file
			}
		} catch (e) { /* fall through */ }
		return null
	}

	function refreshSidebar() {
		try {
			var store = sidebarStore()
			if (store && store.currentNode) {
				var node = store.currentNode
				store.close()
				setTimeout(function () { store.open(node, 'sharing') }, 150)
			}
		} catch (e) { /* cosmetic */ }
	}

	function readCatalogListed(share) {
		// OCS serializes share attributes as a JSON string of
		// [{scope, key, enabled|value}]; files_picocms stores catalog_listed there.
		try {
			var attrs = typeof share.attributes === 'string' ? JSON.parse(share.attributes) : (share.attributes || [])
			for (var i = 0; i < attrs.length; i++) {
				if (attrs[i].scope === 'files_picocms' && attrs[i].key === 'catalog_listed') {
					return !!(attrs[i].enabled !== undefined ? attrs[i].enabled : attrs[i].value)
				}
			}
		} catch (e) { /* ignore */ }
		return false
	}

	function showDialog(path) {
		var overlay = el('div', { style: OVERLAY_STYLE })
		var box = el('div', { style: BOX_STYLE })
		overlay.appendChild(box)
		document.body.appendChild(overlay)
		function close() { overlay.remove() }
		overlay.addEventListener('click', function (e) { if (e.target === overlay) { close() } })

		// ONE public link per file (old-service model — several links for the same
		// data is asking for trouble). If one exists, open it for viewing/editing
		// instead of minting another; the warning is only for CREATION.
		box.appendChild(el('p', { text: t('files_sharding', 'Loading…'), style: 'margin:0;' }))
		ocs('GET', '/ocs/v2.php/apps/files_sharing/api/v1/shares?path=' + encodeURIComponent(path))
			.then(function (res) {
				var shares = (res.ocs && res.ocs.data) || []
				var link = shares.filter(function (s) { return s.share_type === 3 })[0]
				if (link) {
					showLinkPhase(box, path, link, close)
				} else {
					showCreatePhase(box, path, close)
				}
			})
			.catch(function () { showCreatePhase(box, path, close) })
	}

	function showCreatePhase(box, path, close) {
		box.textContent = ''

		// Warning + confirm (creation only)
		var msg = el('p', { text: warningText(), style: 'margin:0 0 16px 0;line-height:1.5;' })
		var row = el('div', { style: 'text-align:right;margin-top:12px;' })
		var cancel = el('button', { text: t('files_sharding', 'Cancel'), style: BTN })
		var accept = el('button', { text: t('files_sharding', 'Proceed'), style: BTN_PRIMARY })
		row.appendChild(cancel); row.appendChild(accept)
		box.appendChild(el('h3', { text: t('files_sharding', 'Create public link'), style: 'margin:0 0 12px 0;' }))
		box.appendChild(msg); box.appendChild(row)
		cancel.addEventListener('click', close)

		accept.addEventListener('click', function () {
			accept.disabled = true
			accept.textContent = t('files_sharding', 'Creating…')
			ocs('POST', '/ocs/v2.php/apps/files_sharing/api/v1/shares', { path: path, shareType: 3, permissions: 1 })
				.then(function (res) {
					var meta = res.ocs && res.ocs.meta
					var data = res.ocs && res.ocs.data
					if (!meta || meta.statuscode < 200 || meta.statuscode >= 300 || !data || !data.token) {
						msg.textContent = (meta && meta.message) || t('files_sharding', 'Creating the link failed')
						accept.remove()
						return
					}
					showLinkPhase(box, path, data, close)
				})
				.catch(function () { msg.textContent = t('files_sharding', 'Creating the link failed') })
		})
	}

	function showLinkPhase(box, path, share, close) {
		box.textContent = ''
		var origin = window.location.origin + (OC.webroot || '')
		var base = origin + '/index.php/s/'

		box.appendChild(el('h3', { text: t('files_sharding', 'Public link'), style: 'margin:0 0 12px 0;' }))

		// URL row: fixed prefix + editable last segment + open + copy
		var urlRow = el('div', { style: 'display:flex;align-items:center;gap:4px;flex-wrap:wrap;margin-bottom:14px;' })
		var prefix = el('span', { text: base, style: 'font-family:monospace;font-size:12px;color:var(--color-text-maxcontrast,#666);' })
		var nameInput = el('input', { type: 'text', value: share.token, style: 'font-family:monospace;font-size:12px;padding:4px 6px;border:1px solid var(--color-border-dark,#ccc);border-radius:4px;min-width:150px;' })
		var openA = el('a', { href: base + share.token, target: '_blank', rel: 'noopener', text: '↗', title: t('files_sharding', 'Open link'), style: 'text-decoration:none;font-size:16px;padding:0 4px;' })
		var copyBtn = el('button', { text: '⧉', title: t('files_sharding', 'Copy link'), style: BTN + 'padding:4px 10px;' })
		urlRow.appendChild(prefix); urlRow.appendChild(nameInput); urlRow.appendChild(openA); urlRow.appendChild(copyBtn)
		box.appendChild(urlRow)
		copyBtn.addEventListener('click', function () {
			navigator.clipboard && navigator.clipboard.writeText(base + nameInput.value.trim())
			copyBtn.textContent = '✓'
			setTimeout(function () { copyBtn.textContent = '⧉' }, 1200)
		})
		nameInput.addEventListener('input', function () { openA.href = base + nameInput.value.trim() })

		// Public-dataset checkbox (only when files_picocms is enabled)
		var listedBox = null
		var wasListed = readCatalogListed(share)
		if (window.OC && OC.appswebroots && OC.appswebroots.files_picocms) {
			var lbl = el('label', { style: 'display:flex;align-items:center;gap:8px;margin:4px 0 10px 0;cursor:pointer;' })
			listedBox = el('input', { type: 'checkbox' })
			listedBox.checked = wasListed
			lbl.appendChild(listedBox)
			lbl.appendChild(el('span', { text: t('files_sharding', 'List in the public dataset catalog') }))
			box.appendChild(lbl)
		}

		box.appendChild(el('p', {
			text: t('files_sharding', 'Password, expiration date and permissions can be set on the link entry in the sharing panel.'),
			style: 'margin:4px 0 12px 0;font-size:12px;color:var(--color-text-maxcontrast,#666);',
		}))

		var row = el('div', { style: 'text-align:right;' })
		var done = el('button', { text: t('files_sharding', 'Done'), style: BTN_PRIMARY })
		row.appendChild(done)
		box.appendChild(row)

		done.addEventListener('click', function () {
			done.disabled = true
			var jobs = []
			var newName = nameInput.value.trim()
			if (newName && newName !== share.token) {
				jobs.push(ocs('PUT', '/ocs/v2.php/apps/files_sharding/api/v1/link-name', { path: path, name: newName })
					.then(function (r) {
						var m = r.ocs && r.ocs.meta
						if (!m || m.statuscode < 200 || m.statuscode >= 300) {
							throw new Error((r.ocs && r.ocs.data && r.ocs.data.message) || (m && m.message) || t('files_sharding', 'Renaming the link failed'))
						}
					}))
			}
			if (listedBox && listedBox.checked !== wasListed) {
				jobs.push(ocs('POST', '/ocs/v2.php/apps/files_picocms/api/v1/catalog',
					{ fileId: share.file_source, listed: listedBox.checked }))
			}
			Promise.all(jobs)
				.then(function () {
					close()
					refreshSidebar()
				})
				.catch(function (e) {
					done.disabled = false
					OC.Notification && OC.Notification.showTemporary(String(e && e.message || e))
				})
		})
	}

	// decodeURIComponent anchor: keeps minifiers from dropping the block.
	decodeURIComponent('')

	document.addEventListener('click', function (e) {
		var btn = e.target && e.target.closest && e.target.closest('.new-share-link')
		if (!btn) { return }
		var path = currentSidebarPath()
		if (!path) {
			console.warn('[files_sharding] public-link popup: cannot resolve sidebar file — falling back to the stock flow')
			return
		}
		e.preventDefault()
		e.stopPropagation()
		showDialog(path)
	}, true)
})()

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
	var BOX_STYLE = 'background:var(--color-main-background,#fff);color:var(--color-main-text,#222);border-radius:var(--border-radius-large,10px);max-width:520px;width:92%;padding:20px 24px;box-shadow:0 2px 24px rgba(0,0,0,.4);font-size:15px;'
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

		// URL row: fixed prefix + editable last segment + open + copy.
		// Icons match the sharing pane's copy button: MDI glyph in a circular
		// hover button. One line — prefix shrinks with ellipsis, icons never wrap.
		var ICON_BTN = 'flex:0 0 auto;width:36px;height:36px;border-radius:50%;border:none;background:transparent;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;color:inherit;line-height:0;padding:0;'
		var SVG_COPY = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style="width:20px;height:20px;min-width:20px;min-height:20px" fill="currentColor"><path d="M19,21H8V7H19M19,5H8A2,2 0 0,0 6,7V21A2,2 0 0,0 8,23H19A2,2 0 0,0 21,21V7A2,2 0 0,0 19,5M16,1H4A2,2 0 0,0 2,3V17H4V3H16V1Z"/></svg>'
		var SVG_OPEN = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style="width:20px;height:20px;min-width:20px;min-height:20px" fill="currentColor"><path d="M14,3V5H17.59L7.76,14.83L9.17,16.24L19,6.41V10H21V3M19,19H5V5H12V3H5C3.89,3 3,3.9 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V12H19V19Z"/></svg>'
		function hoverize(b) {
			b.addEventListener('mouseenter', function () { b.style.background = 'var(--color-background-hover,#ededed)' })
			b.addEventListener('mouseleave', function () { b.style.background = 'transparent' })
		}
		var urlRow = el('div', { style: 'display:flex;align-items:center;gap:2px;flex-wrap:nowrap;margin-bottom:14px;' })
		var prefix = el('span', { text: base, title: base, style: 'font-family:monospace;font-size:13px;color:var(--color-text-maxcontrast,#666);flex:0 1 auto;min-width:40px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;' })
		var nameInput = el('input', { type: 'text', value: share.token, style: 'font-family:monospace;font-size:13px;padding:6px 8px;border:1px solid var(--color-border-dark,#ccc);border-radius:4px;flex:1 1 120px;min-width:100px;' })
		var openA = el('a', { href: base + share.token, target: '_blank', rel: 'noopener', title: t('files_sharding', 'Open link'), style: ICON_BTN })
		openA.innerHTML = SVG_OPEN
		var copyBtn = el('button', { title: t('files_sharding', 'Copy link'), style: ICON_BTN })
		copyBtn.innerHTML = SVG_COPY
		hoverize(openA); hoverize(copyBtn)
		urlRow.appendChild(prefix); urlRow.appendChild(nameInput); urlRow.appendChild(openA); urlRow.appendChild(copyBtn)
		box.appendChild(urlRow)
		copyBtn.addEventListener('click', function () {
			navigator.clipboard && navigator.clipboard.writeText(base + nameInput.value.trim())
			var old = copyBtn.innerHTML
			copyBtn.textContent = '✓'
			setTimeout(function () { copyBtn.innerHTML = old }, 1200)
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
			text: t('files_sharding', 'Password, expiration date and permissions can be set on the link entry in the sharing panel. One public link per file or folder — creating additional links is disabled.'),
			style: 'margin:4px 0 12px 0;font-size:13px;color:var(--color-text-maxcontrast,#666);',
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

	// Rename the stock "Add another link" menu entry — inaccurate under the
	// one-link policy (clicking it opens the existing link for editing).
	// Class-targeted (locale-independent); a menu-only variant carries a text
	// span, the icon-only create "+" does not and is left alone. Fails safe:
	// if the markup changes the observer simply never matches.
	function relabelAddAnother(rootNode) {
		var items = (rootNode.querySelectorAll ? rootNode : document).querySelectorAll('.new-share-link')
		items.forEach && items.forEach(function (item) {
			var span = item.querySelector && item.querySelector('button span:not([class*="icon"])')
			if (span && span.textContent.trim() !== '' && !item.dataset.fshRelabeled) {
				item.dataset.fshRelabeled = '1'
				span.textContent = t('files_sharding', 'Show public link')
				// swap the stock "+" for a chain-link glyph (mdi link-variant)
				var svg = item.querySelector('svg')
				if (svg) {
					var path = svg.querySelector('path')
					if (path) { path.setAttribute('d', 'M10.59,13.41C11,13.8 11,14.44 10.59,14.83C10.2,15.22 9.56,15.22 9.17,14.83C7.22,12.88 7.22,9.71 9.17,7.76V7.76L12.71,4.22C14.66,2.27 17.83,2.27 19.78,4.22C21.73,6.17 21.73,9.34 19.78,11.29L18.29,12.78C18.3,11.96 18.17,11.14 17.89,10.36L18.36,9.88C19.54,8.71 19.54,6.81 18.36,5.64C17.19,4.46 15.29,4.46 14.12,5.64L10.59,9.17C9.41,10.34 9.41,12.24 10.59,13.41M13.41,9.17C13.8,8.78 14.44,8.78 14.83,9.17C16.78,11.12 16.78,14.29 14.83,16.24V16.24L11.29,19.78C9.34,21.73 6.17,21.73 4.22,19.78C2.27,17.83 2.27,14.66 4.22,12.71L5.71,11.22C5.7,12.04 5.83,12.86 6.11,13.65L5.64,14.12C4.46,15.29 4.46,17.19 5.64,18.36C6.81,19.54 8.71,19.54 9.88,18.36L13.41,14.83C14.59,13.66 14.59,11.76 13.41,10.59C13,10.2 13,9.56 13.41,9.17Z') }
				}
			}
		})
	}
	try {
		new MutationObserver(function (muts) {
			muts.forEach(function (m) {
				m.addedNodes.forEach(function (n) {
					if (n.nodeType === 1) { relabelAddAnother(n) }
				})
			})
		}).observe(document.body, { childList: true, subtree: true })
	} catch (e) { /* cosmetic */ }

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

/**
 * "Set public link name…" file action (old-service parity: a public link's URL
 * string may be a user-chosen name instead of the random token).
 *
 * Plain JS, no build. Registers through the modern file-action registry by
 * pushing into window._nc_fileactions (what @nextcloud/files' registerFileAction
 * does); the object only needs the documented method shape.
 */
(function () {
	'use strict'

	var PENCIL = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M10.59,13.41C11,13.8 11,14.44 10.59,14.83C10.2,15.22 9.56,15.22 9.17,14.83C7.22,12.88 7.22,9.71 9.17,7.76V7.76L12.71,4.22C14.66,2.27 17.83,2.27 19.78,4.22C21.73,6.17 21.73,9.34 19.78,11.29L18.29,12.78C18.3,11.96 18.17,11.14 17.89,10.36L18.36,9.88C19.54,8.71 19.54,6.81 18.36,5.64C17.19,4.46 15.29,4.46 14.12,5.64L10.59,9.17C9.41,10.34 9.41,12.24 10.59,13.41M13.41,9.17C13.8,8.78 14.44,8.78 14.83,9.17C16.78,11.12 16.78,14.29 14.83,16.24V16.24L11.29,19.78C9.34,21.73 6.17,21.73 4.22,19.78C2.27,17.83 2.27,14.66 4.22,12.71L5.71,11.22C5.7,12.04 5.83,12.86 6.11,13.65L5.64,14.12C4.46,15.29 4.46,17.19 5.64,18.36C6.81,19.54 8.71,19.54 9.88,18.36L13.41,14.83C14.59,13.66 14.59,11.76 13.41,10.59C13,10.2 13,9.56 13.41,9.17Z"/></svg>'

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

	function notify(msg) {
		if (window.OC && OC.Notification && OC.Notification.showTemporary) {
			OC.Notification.showTemporary(msg)
		} else {
			alert(msg)
		}
	}

	function run(node) {
		var path = (node.path || '/')
		ocs('GET', '/ocs/v2.php/apps/files_sharing/api/v1/shares?path=' + encodeURIComponent(path))
			.then(function (res) {
				var shares = (res.ocs && res.ocs.data) || []
				var link = shares.filter(function (s) { return s.share_type === 3 })[0]
				if (!link) {
					notify(t('files_sharding', 'Create a public link for this file first (Share → Share link).'))
					return
				}
				var ask = function (cb) {
					if (window.OC && OC.dialogs && OC.dialogs.prompt) {
						OC.dialogs.prompt(
							t('files_sharding', 'Choose the name used in the public link URL (letters, digits, dot, dash, underscore). Anyone with the old link will need the new one.'),
							t('files_sharding', 'Public link name'),
							function (ok, value) { if (ok) cb(value) },
							true,
							t('files_sharding', 'Name'),
							false
						)
					} else {
						var v = prompt(t('files_sharding', 'Public link name:'), link.token)
						if (v !== null) cb(v)
					}
				}
				ask(function (name) {
					name = (name || '').trim()
					if (!name || name === link.token) { return }
					ocs('PUT', '/ocs/v2.php/apps/files_sharding/api/v1/link-name', { path: path, name: name })
						.then(function (res2) {
							var meta = res2.ocs && res2.ocs.meta
							var data = res2.ocs && res2.ocs.data
							if (meta && meta.statuscode >= 200 && meta.statuscode < 300 && data && data.url) {
								notify(t('files_sharding', 'Public link renamed: {url}', { url: data.url }))
								if (navigator.clipboard) { navigator.clipboard.writeText(data.url).catch(function () {}) }
							} else {
								notify((meta && meta.message) || t('files_sharding', 'Renaming the link failed'))
							}
						})
				})
			})
			.catch(function () { notify(t('files_sharding', 'Renaming the link failed')) })
	}

	// decodeURIComponent anchor: keeps minifiers from dropping the block.
	decodeURIComponent('')

	window._nc_fileactions = window._nc_fileactions || []
	window._nc_fileactions.push({
		id: 'fsh-link-name',
		displayName: function () { return t('files_sharding', 'Set public link name…') },
		iconSvgInline: function () { return PENCIL },
		order: 60,
		enabled: function (nodes, view) {
			if (!nodes || nodes.length !== 1) { return false }
			// Show wherever a user manages their own files (incl. 'personal',
			// favorites, recent); exclude read-only/trash surfaces.
			var id = (view && view.id) || ''
			return id !== 'trashbin' && id !== 'uga-sponsored' && id.indexOf('deleted') === -1
		},
		exec: function (node) { run(node); return Promise.resolve(null) },
	})
})()

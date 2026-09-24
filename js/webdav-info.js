/* global OC, t */
/**
 * Shared by webdav-settings.js and device-password.js: the facts a WebDAV
 * client needs, from the page (OC.getCurrentUser) and the initial state
 * WebDavInfoListener provides. The username is shown exactly as it must be
 * typed — never URL-encoded, as it is inside core's WebDAV URL.
 */
window.FilesShardingWebDav = (function () {
	'use strict';
	function state() {
		var el = document.getElementById('initial-state-files_sharding-webdav');
		try { return el ? JSON.parse(atob(el.value)) : {}; } catch (e) { return {}; }
	}
	function info() {
		var s = state();
		var user = (OC.getCurrentUser && OC.getCurrentUser() && OC.getCurrentUser().uid) || '';
		var origin = window.location.origin + (OC.webroot || '');
		var url = s.url || '';
		if (url === '') {
			url = origin + '/remote.php/dav/files/' + encodeURIComponent(user) + '/';
		} else if (url.charAt(0) === '/') {
			url = origin + url;
		}
		return { user: user, url: url, docsUrl: s.docsUrl || '', securityUrl: s.securityUrl || '' };
	}
	function copyRow(label, value) {
		var row = document.createElement('div');
		row.className = 'fsh-webdav-row';
		var l = document.createElement('span');
		l.className = 'fsh-webdav-label';
		l.textContent = label;
		var v = document.createElement('code');
		v.className = 'fsh-webdav-value';
		v.textContent = value;
		var b = document.createElement('button');
		b.type = 'button';
		b.className = 'fsh-webdav-copy';
		b.textContent = t('files_sharding', 'Copy');
		b.addEventListener('click', function () {
			var done = function () {
				b.textContent = t('files_sharding', 'Copied');
				setTimeout(function () { b.textContent = t('files_sharding', 'Copy'); }, 1500);
			};
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(value).then(done, function () {});
			}
		});
		row.appendChild(l);
		row.appendChild(v);
		row.appendChild(b);
		return row;
	}
	return { info: info, copyRow: copyRow };
})();

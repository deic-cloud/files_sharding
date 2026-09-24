/* global OC, OCA, t, FilesShardingWebDav */
/**
 * Files app settings: our WebDAV section in place of core's (hidden by
 * css/webdav-settings.css). Core's shows a long URL with the username
 * URL-encoded inside and links to a guide about mounting drives; ours gives
 * the server address, the username as typed, the device-password hint, and
 * the deployment's own guide. Registered through the Files settings API
 * (OCA.Files.Settings), so core code is untouched.
 */
(function () {
	'use strict';
	function build() {
		var i = FilesShardingWebDav.info();
		var box = document.createElement('div');
		box.className = 'fsh-webdav';
		var h = document.createElement('h3');
		h.textContent = 'WebDAV';
		box.appendChild(h);
		var p = document.createElement('p');
		p.textContent = t('files_sharding', 'Reach your files from other programs, such as curl or Cyberduck:');
		box.appendChild(p);
		box.appendChild(FilesShardingWebDav.copyRow(t('files_sharding', 'Server'), i.url));
		box.appendChild(FilesShardingWebDav.copyRow(t('files_sharding', 'Username'), i.user));
		var pw = document.createElement('p');
		pw.className = 'fsh-webdav-note';
		pw.appendChild(document.createTextNode(t('files_sharding', 'Password: a device password, not your login.') + ' '));
		var a = document.createElement('a');
		a.href = i.securityUrl || (OC.generateUrl ? OC.generateUrl('/settings/user/security') : '#');
		a.textContent = t('files_sharding', 'Create one under Security');
		pw.appendChild(a);
		box.appendChild(pw);
		var ex = document.createElement('pre');
		ex.className = 'fsh-webdav-example';
		ex.textContent = "curl -u '" + i.user + "' " + i.url;
		box.appendChild(ex);
		if (i.docsUrl) {
			var d = document.createElement('p');
			var da = document.createElement('a');
			da.href = i.docsUrl;
			da.target = '_blank';
			da.rel = 'noopener';
			da.textContent = t('files_sharding', 'How to connect WebDAV clients');
			d.appendChild(da);
			box.appendChild(d);
		}
		return box;
	}
	function register(tries) {
		var S = window.OCA && OCA.Files && OCA.Files.Settings;
		if (S && typeof S.register === 'function' && S.Setting) {
			S.register(new S.Setting('files_sharding-webdav', { el: build, order: 1 }));
			return;
		}
		if (tries > 0) { setTimeout(function () { register(tries - 1); }, 200); }
	}
	register(50);
})();

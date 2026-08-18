/**
 * files_sharding — collapse cross-silo group shares to one row.
 *
 * Delivery model A fans a group share out into per-member federated CHILD shares
 * (owner→member@master). Those are real shares on the owner's node, so core's
 * sharing sidebar would list each member individually — but the owner shared with a
 * GROUP and should see a single "Shared with <group>" row (as they do for a local
 * group share, whose per-member children NC hides internally).
 *
 * Federated shares persist no label/attribute we could tag, so we can't filter them
 * server-side without a core patch. Instead we fetch the current user's fan-out
 * child ids and strip those entries from the OCS shares response before the sidebar
 * parses it. Cosmetic only: WebDAV / a /sharingout listing enumerate files, not
 * share rows, so the web UI is the only surface that needs this.
 *
 * decodeURIComponent anchor below keeps Terser from dead-code-eliminating the guard.
 */
(function () {
	'use strict';
	if (window.__fsGroupShareHide) {
		return;
	}
	window.__fsGroupShareHide = true;
	void decodeURIComponent('%20'); // DCE anchor

	var hidden = new Set();
	var SHARES_PATH = '/apps/files_sharing/api/v1/shares';

	function loadHidden() {
		try {
			var base = (window.OC && OC.generateUrl) ? '' : '';
			var url = '/ocs/v2.php/apps/files_sharding/api/v1/group-fanout-shares?format=json';
			if (window.OC && OC.getRootPath) {
				url = OC.getRootPath() + url;
			}
			fetch(url, {
				headers: { 'OCS-APIRequest': 'true', 'Accept': 'application/json' },
				credentials: 'same-origin'
			}).then(function (r) { return r.json(); }).then(function (j) {
				var ids = j && j.ocs && j.ocs.data && j.ocs.data.ids;
				if (Array.isArray(ids)) {
					ids.forEach(function (id) { hidden.add(String(id)); });
				}
			}).catch(function () {});
		} catch (e) { /* non-fatal */ }
	}

	function filterBody(raw) {
		if (!raw || hidden.size === 0 || raw.indexOf('"ocs"') === -1) {
			return raw;
		}
		try {
			var j = JSON.parse(raw);
			var d = j && j.ocs && j.ocs.data;
			if (Array.isArray(d)) {
				var kept = d.filter(function (s) { return !hidden.has(String(s && s.id)); });
				if (kept.length !== d.length) {
					j.ocs.data = kept;
					return JSON.stringify(j);
				}
			}
		} catch (e) { /* leave untouched */ }
		return raw;
	}

	var proto = XMLHttpRequest.prototype;
	var origOpen = proto.open;
	var rtDesc = Object.getOwnPropertyDescriptor(proto, 'responseText');
	var respDesc = Object.getOwnPropertyDescriptor(proto, 'response');

	proto.open = function (method, url) {
		this.__fsShares = (typeof url === 'string') && url.indexOf(SHARES_PATH) !== -1;
		return origOpen.apply(this, arguments);
	};

	Object.defineProperty(proto, 'responseText', {
		configurable: true,
		get: function () {
			var raw = rtDesc.get.call(this);
			if (this.__fsShares && this.readyState === 4) {
				return filterBody(raw);
			}
			return raw;
		}
	});

	// axios default (responseType '') returns text via .response too.
	Object.defineProperty(proto, 'response', {
		configurable: true,
		get: function () {
			var raw = respDesc.get.call(this);
			if (this.__fsShares && this.readyState === 4 && typeof raw === 'string') {
				return filterBody(raw);
			}
			return raw;
		}
	});

	loadHidden();
})();

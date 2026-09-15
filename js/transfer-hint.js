/* global t */
// Settings → Sharing → "Transfer ownership of a file or folder": append a hint
// that the recipient must be homed on this server (see
// TransferOwnershipMiddleware). The section is Vue-rendered with hashed class
// names, so we anchor on the stock section id and its heading text.
(function () {
	'use strict';
	var HINT_ID = 'files-sharding-transfer-hint';
	function place() {
		if (document.getElementById(HINT_ID)) {
			return true;
		}
		var section = document.getElementById('files-personal-settings');
		if (!section) {
			return false;
		}
		var heading = null;
		section.querySelectorAll('h3, h2, legend').forEach(function (h) {
			if (!heading && /transfer/i.test(h.textContent || '')) {
				heading = h;
			}
		});
		if (!heading) {
			return false;
		}
		var p = document.createElement('p');
		p.id = HINT_ID;
		p.className = 'settings-hint';
		p.textContent = t('files_sharding',
			'Ownership can only be transferred to users whose home is on the same server as yours. Other users of the service are listed by the search, but a transfer to them is refused.');
		// The heading is followed by the stock description paragraph and the form;
		// put the hint right after the heading's own description if there is one.
		var anchor = heading.nextElementSibling && heading.nextElementSibling.tagName === 'P'
			? heading.nextElementSibling : heading;
		anchor.parentNode.insertBefore(p, anchor.nextSibling);
		return true;
	}
	if (place()) {
		return;
	}
	var obs = new MutationObserver(function () {
		if (place()) {
			obs.disconnect();
		}
	});
	obs.observe(document.body, { childList: true, subtree: true });
	setTimeout(function () { obs.disconnect(); }, 15000);
})();

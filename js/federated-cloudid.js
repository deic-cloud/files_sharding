/**
 * files_sharding — correct the "Federated Cloud" settings field on silos.
 *
 * Nextcloud's User::getCloudId() builds the ID against this instance's own (silo)
 * host and is computed inline in core, so — unlike ICloudIdManager (see
 * MasterCloudIdManager) — it can't be overridden from an app. Our federation
 * identity is master-tied, so here we correct, purely in the UI, both the value the
 * stock "Your Federated Cloud ID" field shows and the value its copy button copies
 * (the Vue component captured the silo value at mount, so a DOM fix alone would
 * leave the clipboard wrong). The actual share flow already carries the master-tied
 * ID; this only stops a user from handing out the silo-scoped one by hand.
 */
(function () {
	'use strict';

	var el = document.getElementById('initial-state-files_sharding-federatedCloudId');
	if (!el) { return; }
	var data;
	try { data = JSON.parse(atob(el.value)); } catch (e) { return; }
	var wrong = data && data.wrong;
	var right = data && data.right;
	if (!wrong || !right || wrong === right) { return; }

	var fix = function (s) {
		return (typeof s === 'string' && s.indexOf(wrong) !== -1) ? s.split(wrong).join(right) : s;
	};

	// 1) Correct the readonly display input. cloudId is a const in the Vue component
	//    (set once), so a single pass after mount suffices; the observer just guards
	//    against a late render, and disconnects once things settle.
	var fixInputs = function () {
		var inputs = document.querySelectorAll('.federated-cloud__cloud-id input');
		for (var i = 0; i < inputs.length; i++) {
			if (inputs[i].value && inputs[i].value.indexOf(wrong) !== -1) {
				inputs[i].value = fix(inputs[i].value);
			}
		}
	};
	fixInputs();
	if (window.MutationObserver) {
		var obs = new MutationObserver(fixInputs);
		obs.observe(document.body, { subtree: true, childList: true });
		setTimeout(function () { obs.disconnect(); }, 10000);
	}

	// 2) Correct what the copy button writes (it copies the captured silo value, not
	//    the DOM), so a user copying their ID gets the master-tied one.
	if (navigator.clipboard && navigator.clipboard.writeText) {
		var orig = navigator.clipboard.writeText.bind(navigator.clipboard);
		navigator.clipboard.writeText = function (text) { return orig(fix(String(text))); };
	}
})();

/* global OC, OCP, t */
/**
 * Settings → Security → "Create new app password": add an optional Password
 * field, so a device password can be one the user chooses instead of a random
 * string (see lib/Controller/DevicePasswordController.php). The stock form is a
 * Vue component; we append the field once it is rendered and intercept the
 * submit in the capture phase only when the field is filled — otherwise the
 * stock behaviour (generated password) is untouched.
 */
(function () {
	'use strict';

	var FORM = '#generate-app-token-section';
	var FIELD_ID = 'fsh-device-password';
	// Mirrors core's PublicKeyTokenProvider::TOKEN_MIN_LENGTH (shorter strings
	// are never looked up as tokens). Stock is 22; the ScienceData image patches
	// it to 8 (mfsbsd patch_authtoken_min_length.pl). The server check is
	// authoritative; this only gives an early message.
	var MIN_LENGTH = 8;
	var busy = false;

	function ocsUrl() {
		return (OC.webroot || '') + '/ocs/v2.php/apps/files_sharding/api/v1/device-password?format=json';
	}

	function toast(msg, isError) {
		if (window.OCP && OCP.Toast) {
			isError ? OCP.Toast.error(msg) : OCP.Toast.success(msg);
		} else {
			window.alert(msg);
		}
	}

	function inject(form) {
		if (form.querySelector('#' + FIELD_ID)) return;
		var nameField = form.querySelector('input');
		var button = form.querySelector('button[type="submit"]');
		if (!nameField || !button) return;

		// Clone the stock "App name" NcTextField so the new field gets exactly the
		// same look (its scoped-CSS data-v-* attributes travel with the clone),
		// including the label shown inside the border once something is typed.
		var stock = nameField.closest('.input-field') || nameField.parentNode;
		// Keep the stock class too: its scoped rules set the 44px height, 200px
		// width and the 12px gap towards the button.
		var wrap = stock.cloneNode(true);
		wrap.classList.add('fsh-device-password');
		// The stock class also indents the box 12px from the left; on the second
		// field that would double the gap (12px margin + 12px padding).
		wrap.style.paddingInlineStart = '0';
		var input = wrap.querySelector('input');
		var label = wrap.querySelector('label');
		var labelText = t('files_sharding', 'Password (optional)');
		input.type = 'password';
		input.id = FIELD_ID;
		input.name = FIELD_ID;
		input.value = '';
		input.autocomplete = 'new-password';
		input.removeAttribute('maxlength');
		input.removeAttribute('disabled');
		input.placeholder = labelText;
		input.title = t('files_sharding', 'Choose the password yourself (at least {n} characters) — leave empty to have one generated', { n: MIN_LENGTH });
		input.minLength = MIN_LENGTH;
		if (label) {
			label.textContent = labelText;
			label.htmlFor = FIELD_ID;
		}
		button.parentNode.insertBefore(wrap, button);
	}

	function onSubmit(ev) {
		var form = ev.target;
		if (!(form instanceof HTMLFormElement) || !form.matches(FORM)) return;
		var input = form.querySelector('#' + FIELD_ID);
		if (!input || input.value === '') return; // stock path: generated password
		ev.preventDefault();
		ev.stopImmediatePropagation();
		if (busy) return;

		var nameField = form.querySelector('input:not([type="password"])');
		var name = nameField ? nameField.value.trim() : '';
		if (name === '') {
			toast(t('files_sharding', 'Please give the device password a name'), true);
			return;
		}
		if (input.value.length < MIN_LENGTH) {
			toast(t('files_sharding', 'A device password must be at least {n} characters long', { n: MIN_LENGTH }), true);
			return;
		}
		busy = true;
		fetch(ocsUrl(), {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'OCS-APIREQUEST': 'true',
				'requesttoken': OC.requestToken,
			},
			body: JSON.stringify({ name: name, password: input.value }),
		}).then(function (res) {
			return res.text().then(function (txt) {
				var data = null;
				try { data = JSON.parse(txt); } catch (e) { /* non-JSON error page */ }
				var meta = data && data.ocs && data.ocs.meta;
				if (!res.ok || !meta || meta.statuscode < 200 || meta.statuscode >= 300) {
					var msg = (data && data.ocs && data.ocs.data && data.ocs.data.message)
						|| (meta && meta.message)
						|| (res.status === 404 ? t('files_sharding', 'The endpoint is not available yet — please try again in a while') : 'HTTP ' + res.status);
					throw new Error(msg);
				}
				return data.ocs.data;
			});
		}).then(function (created) {
			toast(t('files_sharding', 'Device password "{name}" created. Use it with your username.', { name: created.name }, undefined, { escape: false }));
			input.value = '';
			if (nameField) nameField.value = '';
			// The list of devices is Vue state we cannot reach; a reload shows the new entry.
			setTimeout(function () { window.location.reload(); }, 1500);
		}).catch(function (e) {
			toast(e.message || t('files_sharding', 'Could not create the device password'), true);
		}).finally(function () { busy = false; });
	}

	function watch() {
		var form = document.querySelector(FORM);
		if (form) inject(form);
		var observer = new MutationObserver(function () {
			var f = document.querySelector(FORM);
			if (f) inject(f);
		});
		observer.observe(document.body, { childList: true, subtree: true });
	}

	document.addEventListener('submit', onSubmit, true);
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', watch);
	} else {
		watch();
	}
})();

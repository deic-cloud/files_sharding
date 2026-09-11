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

		var wrap = document.createElement('div');
		wrap.className = 'fsh-device-password';
		wrap.style.cssText = 'display:inline-flex;flex-direction:column;margin:0 8px;';
		var input = document.createElement('input');
		input.type = 'password';
		input.id = FIELD_ID;
		input.autocomplete = 'new-password';
		input.placeholder = t('files_sharding', 'Password (optional)');
		input.title = t('files_sharding', 'Choose the password yourself — leave empty to have one generated');
		input.style.cssText = 'height:44px;min-width:230px;';
		var hint = document.createElement('small');
		hint.style.cssText = 'color:var(--color-text-maxcontrast);margin-top:2px;';
		hint.textContent = t('files_sharding', 'Leave empty to have a password generated');
		wrap.append(input, hint);
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

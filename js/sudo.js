/**
 * Injected on all authenticated pages on silos.
 *
 * For every password input that requires the current/existing password
 * (dialogs and the change-password form):
 *  • If a valid sudo token exists in the session, auto-fill it and
 *    auto-submit (dialog) or leave the filled value for the user to submit.
 *  • Otherwise inject a visible "Confirm identity via master" link so the
 *    user can trigger the sudo round-trip.  On return the fresh token is
 *    fetched from the server and the field is filled automatically.
 */
(function () {
'use strict';

if (document.body.id === 'body-login') return;

const OCS = '/ocs/v2.php/apps/files_sharding/api/v1';
const nativeSetter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set;

// Always fetch the sudo token from the server — never use sessionStorage.
// A cached stale token (from a previous session in the same browser tab)
// would produce "Wrong password" after logout+login because exchange()
// clears the server-side token but sessionStorage persists across navigations.
const tokenP = (async function () {
  try {
    const r = await fetch(OCS + '/sudo/token?format=json', {
      headers: {
        'OCS-APIREQUEST': 'true',
        'requesttoken': window.OC?.requestToken || '',
      },
    });
    if (!r.ok) return '';
    const d = await r.json();
    return d.ocs?.data?.token || '';
  } catch (_) {
    return '';
  }
})();

// ── Helpers ───────────────────────────────────────────────────────────────────

function isInDialog(el) {
  return !!el.closest('[role=dialog],[role=alertdialog]');
}

function isEligible(inp) {
  if (isInDialog(inp)) return true;
  // Non-dialog current-password fields (e.g. change-password form in settings).
  const ac   = inp.getAttribute('autocomplete') || '';
  const name = inp.getAttribute('name') || '';
  return ac === 'current-password' || name === 'oldpassword';
}

function setValue(inp, val) {
  if (nativeSetter) nativeSetter.call(inp, val);
  else inp.value = val;
  inp.dispatchEvent(new Event('input', { bubbles: true }));
  inp.dispatchEvent(new Event('change', { bubbles: true }));
}

function clickConfirm(inp) {
  // Walk up to the nearest dialog or form to scope button search.
  const scope = inp.closest('[role=dialog],[role=alertdialog],form');
  if (!scope) return;
  const btn = scope.querySelector([
    'button[type=submit]:not([disabled])',
    'button.primary:not([disabled])',
    '.button-vue--vue-primary:not([disabled])',
    'button[variant=primary]:not([disabled])',
  ].join(','));
  btn?.click();
}

function autofill(inp, tok) {
  setValue(inp, tok);
  // Auto-submit only for dialogs; password-change forms need the user to
  // fill in their new password too.
  if (isInDialog(inp)) {
    setTimeout(() => clickConfirm(inp), 150);
  }
}

function initiateUrl() {
  const returnTo = encodeURIComponent(window.location.pathname);
  const base = window.OC?.generateUrl
    ? window.OC.generateUrl('/apps/files_sharding/sudo/initiate')
    : '/index.php/apps/files_sharding/sudo/initiate';
  return base + '?returnTo=' + returnTo;
}

function injectLink(inp) {
  if (inp.dataset.fshLinked) return;
  inp.dataset.fshLinked = '1';

  const a = document.createElement('a');
  a.href = initiateUrl();
  a.style.cssText = 'display:block;margin:.5em 0;font-size:.9em;color:var(--color-primary-element,#006aa3)';
  a.textContent = 'No password? Confirm identity via master server';

  // In a dialog: append the link inside the dialog so it is always visible.
  const dialog = inp.closest('[role=dialog],[role=alertdialog]');
  if (dialog) {
    // Prefer inserting before the action-button row; fall back to appending.
    const actions = dialog.querySelector('.dialog__actions,.modal-footer,.footer,[class*="actions"]');
    if (actions) {
      actions.insertAdjacentElement('beforebegin', a);
    } else {
      // Walk to a sensible ancestor (not the raw input wrapper).
      const host = inp.closest('.input-field,.nc-password-field') || inp.parentElement || inp;
      host.insertAdjacentElement('afterend', a);
    }
    return;
  }

  // Outside a dialog (e.g. "Current password" form field): insert right after
  // the NcPasswordField wrapper, if present, otherwise after the input.
  const wrapper = inp.closest('.nc-password-field,.input-field,[class*=passwordField]') || inp;
  wrapper.insertAdjacentElement('afterend', a);
}

async function handle(inp) {
  if (!isEligible(inp)) return;

  const tok = await tokenP;

  // Re-check: element might have been removed while awaiting.
  if (!inp.isConnected) return;

  if (tok) {
    autofill(inp, tok);
  } else {
    injectLink(inp);
  }
}

// ── Watchers ─────────────────────────────────────────────────────────────────

// Catch dynamically added inputs: Vue dialogs, SPA navigation, teleported modals.
new MutationObserver(mutations => {
  for (const m of mutations) {
    for (const node of m.addedNodes) {
      if (node.nodeType !== Node.ELEMENT_NODE) continue;
      const inputs = node.matches?.('input[type=password]')
        ? [node]
        : [...node.querySelectorAll('input[type=password]')];
      for (const inp of inputs) handle(inp);
    }
  }
}).observe(document.body, { childList: true, subtree: true });

// Handle inputs already present when the script runs.
document.querySelectorAll('input[type=password]').forEach(inp => handle(inp));
})();

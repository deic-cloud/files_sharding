/**
 * Injected on the silo login page when a master server is configured.
 *
 * Adds a "Log in via master server" link below the login form.
 */
(function () {
'use strict';

if (document.body.id !== 'body-login') return;

const base = window.OC?.generateUrl
  ? window.OC.generateUrl('/apps/files_sharding/master-login')
  : '/index.php/apps/files_sharding/master-login';

const returnPath = new URLSearchParams(window.location.search).get('redirect_url') || '';
const href = base + (returnPath ? '?return=' + encodeURIComponent(returnPath) : '');

const a = document.createElement('a');
a.setAttribute('data-fsh-master-login', '1');
a.href = href;
a.textContent = 'Log in via master server';
a.style.cssText = 'display:block;margin-top:1.5em;text-align:center;font-size:.9em;'
  + 'color:var(--color-primary-element,#006aa3)';

function tryInsert() {
  const form = document.querySelector('form[name=login]');
  if (form) {
    // Sub-views like "Log in with device" reuse form[name=login] but have no
    // password field — hide the link there.
    if (!form.querySelector('input[type=password]')) {
      a.remove();
      return false;
    }
    // Re-insert whenever the link drifts from its expected position.
    if (form.nextElementSibling !== a) {
      form.insertAdjacentElement('afterend', a);
    }
    return true;
  }

  // Fall back to the static .guest-content wrapper (never managed by Vue).
  const container = document.querySelector('.guest-content');
  if (container) {
    if (!container.contains(a)) {
      container.appendChild(a);
    }
    return true;
  }

  return false;
}

const obs = new MutationObserver(() => tryInsert());
obs.observe(document.body, { childList: true, subtree: true });
tryInsert();
})();

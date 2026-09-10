<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;

/**
 * Keeps oc_preferences free of dead "remember me" tokens.
 *
 * Core stores one `login_token` row per browser login that sets the
 * remember-me cookie (every Apache/SAML login does) and only ever touches a
 * row again when that exact cookie comes back — a browser that was closed,
 * whose cookie expired, or that logged in afresh leaves its row behind for
 * good; core has no cleanup at all (not even on logout). Rows are inert
 * without the matching cookie, but they accumulate per login, forever.
 *
 * Instead of another background job, prune on the user's own login and
 * logout: rows older than the cookie's lifetime (remember_login_cookie_lifetime,
 * default 15 days) can no longer be redeemed by any browser and go; on logout
 * the token the leaving browser presents goes too. Tokens younger than the
 * lifetime are kept — another browser of the same user may still hold them.
 */
class LoginTokenPruner {
	public function __construct(
		private IConfig      $config,
		private ITimeFactory $timeFactory,
	) {
	}

	/** @return int rows removed */
	public function prune(string $uid, ?string $currentToken = null): int {
		$lifetime = $this->config->getSystemValueInt('remember_login_cookie_lifetime', 60 * 60 * 24 * 15);
		$cutoff   = $this->timeFactory->getTime() - $lifetime;
		$removed  = 0;
		foreach ($this->config->getUserKeys($uid, 'login_token') as $token) {
			$issued = (int)$this->config->getUserValue($uid, 'login_token', $token, '0');
			if ($issued < $cutoff || ($currentToken !== null && $token === $currentToken)) {
				$this->config->deleteUserValue($uid, 'login_token', $token);
				$removed++;
			}
		}
		return $removed;
	}
}

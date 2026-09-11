<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Controller;

use OC\Authentication\Exceptions\InvalidTokenException;
use OC\Authentication\Exceptions\PasswordlessTokenException;
use OC\Authentication\Token\IProvider;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Authentication\Token\IToken;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\HintException;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;
use OCP\Security\Events\ValidatePasswordPolicyEvent;
use OCP\Security\PasswordContext;
use OCP\Session\Exceptions\SessionNotAvailableException;

/**
 * Device (app) passwords of the user's own choosing.
 *
 * Nextcloud's token store does not care what the secret string is — it hashes
 * whatever is passed to IProvider::generateToken() — but the stock
 * "Create new app password" form only ever generates a random one. In a
 * deployment without user-chosen account passwords (institutional login + this
 * app's hidden password form) that makes every script and WebDAV client depend
 * on a 72-character random string. This endpoint creates a permanent app token
 * from a password the user typed, after the password-policy app has approved
 * it; the token then shows up (and can be revoked) in Settings → Security →
 * Devices & sessions like any other. The field itself is injected into the
 * stock form by js/device-password.js — no core change.
 */
class DevicePasswordController extends OCSController {
	public function __construct(
		string                    $appName,
		IRequest                  $request,
		private IProvider         $tokenProvider,
		private ISession          $session,
		private IUserSession      $userSession,
		private IConfig           $config,
		private IEventDispatcher  $dispatcher,
		private IL10N             $l,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 300)]
	public function create(string $name = '', string $password = ''): DataResponse {
		$name     = trim($name);
		$password = (string)$password;
		if ($name === '' || $password === '') {
			return new DataResponse(['message' => $this->l->t('Name and password are required')], Http::STATUS_BAD_REQUEST);
		}
		if (!$this->config->getSystemValueBool('auth_can_create_app_token', true)) {
			return new DataResponse(['message' => $this->l->t('Creating app passwords is disabled')], Http::STATUS_SERVICE_UNAVAILABLE);
		}
		$user = $this->userSession->getUser();
		if ($user === null || $this->userSession->getImpersonatingUserID() !== null) {
			return new DataResponse(['message' => $this->l->t('Not available')], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		// Same rules as an account password (password_policy app: length, common
		// passwords, HIBP if enabled). It throws a HintException with the reason.
		try {
			$this->dispatcher->dispatchTyped(new ValidatePasswordPolicyEvent($password, PasswordContext::ACCOUNT));
		} catch (HintException $e) {
			return new DataResponse(['message' => $e->getHint() ?: $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		// It must be usable as a device password: never the account's own login
		// name, and the token store needs it to look nothing like a session id.
		if (strcasecmp($password, $user->getUID()) === 0) {
			return new DataResponse(['message' => $this->l->t('The password must differ from your username')], Http::STATUS_BAD_REQUEST);
		}

		// Browser session: copy login name + account password from the session
		// token like core does (the password lets NC re-validate the token against
		// password backends). No session token (e.g. an HTTP-basic-auth call): a
		// password-less token for the uid — what SAML accounts get anyway.
		$loginName       = $user->getUID();
		$accountPassword = null;
		try {
			$sessionId    = $this->session->getId();
			$sessionToken = $this->tokenProvider->getToken($sessionId);
			$loginName    = $sessionToken->getLoginName() ?: $loginName;
			try {
				$accountPassword = $this->tokenProvider->getPassword($sessionToken, $sessionId);
			} catch (PasswordlessTokenException) {
				$accountPassword = null;
			}
		} catch (SessionNotAvailableException | InvalidTokenException) {
			// keep the fallbacks above
		}

		if (mb_strlen($name) > 120) {
			$name = mb_substr($name, 0, 120) . '…';
		}
		$token = $this->tokenProvider->generateToken(
			$password,
			$user->getUID(),
			$loginName,
			$accountPassword,
			$name,
			IToken::PERMANENT_TOKEN,
			IToken::DO_NOT_REMEMBER,
		);

		return new DataResponse([
			'id'   => $token->getId(),
			'name' => $token->getName(),
		]);
	}
}

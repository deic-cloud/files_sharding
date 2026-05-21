<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Middleware;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;

/**
 * Allows silo users to change their NC account password without knowing
 * the current (randomly-generated) password.
 *
 * When the settings change-password form is submitted with an empty
 * "Current password" field, this middleware injects the credential that was
 * stored at account-creation time so DB::checkPassword() can verify it.
 * Once the user has set their own password the stored credential is deleted
 * and they are on their own (or can use the sudo flow as a fallback).
 *
 * As a fallback, if no session password is available but a valid sudo token
 * exists, that token is injected instead (X509Backend accepts it via
 * UserManager::checkPassword()).
 *
 * Must be registered as a global middleware (true) so it catches requests
 * directed at OCA\Settings\Controller\ChangePasswordController.
 */
class SudoPasswordMiddleware extends Middleware {
	private const SUDO_TTL = 300;

	public function __construct(
		private ISession     $session,
		private IUserSession $userSession,
		private IConfig      $config,
		private IRequest     $request,
	) {}

	public function beforeController(Controller $controller, string $methodName): void {
		if (get_class($controller) !== 'OCA\\Settings\\Controller\\ChangePasswordController') return;
		if ($methodName !== 'changePersonalPassword') return;

		// Prefer the session password stored at account-creation time.
		$credential = (string)($this->session->get('fsh_session_password') ?? '');

		// Fall back to the sudo token (requires a prior master-identity confirmation).
		if ($credential === '') {
			$sudoToken = (string)($this->session->get('fsh_sudo_token') ?? '');
			$at        = (int)($this->session->get('fsh_sudo_token_at') ?? 0);
			if ($sudoToken !== '' && (time() - $at) <= self::SUDO_TTL) {
				$credential = $sudoToken;
			}
		}

		if ($credential === '') return;
		$this->injectIfEmpty($credential);
	}

	public function afterController(Controller $controller, string $methodName, Response $response): Response {
		if (get_class($controller) !== 'OCA\\Settings\\Controller\\ChangePasswordController') return $response;
		if ($methodName !== 'changePersonalPassword') return $response;
		if (!($response instanceof JSONResponse)) return $response;

		$data = $response->getData();
		if (($data['status'] ?? '') === 'success') {
			// User has now set a password they chose — discard our stored copy.
			$userId = $this->userSession->getUser()?->getUID() ?? '';
			if ($userId !== '') {
				$this->config->deleteUserValue($userId, 'files_sharding', 'session_pw');
			}
			$this->session->remove('fsh_session_password');
		}
		return $response;
	}

	/**
	 * Injects $credential as the 'oldpassword' request parameter when the
	 * client submitted an empty value.
	 *
	 * The NC Request object decodes the JSON body lazily.  We force that
	 * decoding via reflection, then write our credential into the parsed
	 * parameter array before the controller's method parameters are bound.
	 */
	private function injectIfEmpty(string $credential): void {
		$ro = new \ReflectionObject($this->request);

		// Trigger lazy JSON decode (reads php://input exactly once).
		$decode = $ro->getMethod('decodeContent');
		$decode->setAccessible(true);
		$decode->invoke($this->request);

		$prop  = $ro->getProperty('items');
		$prop->setAccessible(true);
		$items = $prop->getValue($this->request);

		// Only inject when the client sent an empty value.
		if (($items['parameters']['oldpassword'] ?? '') !== '') return;

		$items['parameters']['oldpassword'] = $credential;
		$items['post']['oldpassword']        = $credential;
		$items['params']['oldpassword']      = $credential;
		$prop->setValue($this->request, $items);
	}
}

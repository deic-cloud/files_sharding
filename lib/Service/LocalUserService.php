<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Service;

use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ICrypto;

/**
 * The node-local account of a user who lives on this node (a silo). Created the
 * same way whether the user arrives by the master→silo login exchange or is
 * prepared ahead of time (migration: files_sharding:user:prepare on the master), so a
 * prepared account is indistinguishable from one made at first login.
 */
class LocalUserService {
	public function __construct(
		private IUserManager $userManager,
		private IConfig      $config,
		private ICrypto      $crypto,
	) {
	}

	/**
	 * Create the local account if it does not exist yet. A new account gets a
	 * random password that we keep encrypted (SudoPasswordMiddleware hands it to
	 * the change-password form until the user sets their own).
	 *
	 * @return array{user: IUser, created: bool, password: string} password is the
	 *         plaintext of a NEW account's generated password, '' otherwise
	 */
	public function ensure(string $userId, string $displayName = '', string $email = '', string $quota = ''): array {
		$user = $this->userManager->get($userId);
		$created = false;
		$password = '';
		if ($user === null) {
			$password = bin2hex(random_bytes(32));
			$user = $this->userManager->createUser($userId, $password);
			if ($user === false || $user === null) {
				throw new \RuntimeException("Could not create local account {$userId}");
			}
			$this->config->setUserValue($userId, 'files_sharding', 'session_pw', $this->crypto->encrypt($password));
			$created = true;
		}
		if ($displayName !== '' && $user->getDisplayName() !== $displayName) {
			$user->setDisplayName($displayName);
		}
		if ($email !== '' && $user->getEMailAddress() !== $email) {
			$user->setEMailAddress($email);
		}
		if ($quota !== '' && $user->getQuota() !== $quota) {
			$user->setQuota($quota);
		}
		return ['user' => $user, 'created' => $created, 'password' => $password];
	}
}

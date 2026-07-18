<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;

/**
 * Maps a group ("VO") to a newline-separated list of its members' registered
 * X.509 certificate subject DNs — used by the batch/GridFactory system for VO
 * membership checks. The DNs are the ones stored by the personal X.509 settings
 * / CertificateService in oc_preferences (app=files_sharding, key=x509_dn_0..9)
 * — the same registry X509Backend authenticates against.
 *
 * Replaces the old ownCloud `remote.php/groupadmin?action=listMembers&format=x509`
 * endpoint; the silo Apache config rewrites `/vos/<group>` here.
 */
class VoController extends Controller {
	private const MAX_DNS = 10;

	public function __construct(
		string                $appName,
		IRequest              $request,
		private IGroupManager $groupManager,
		private IConfig       $config,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Plain-text: one X.509 DN per line for every certificate registered by a
	 * member of $gid. Empty body (200) for an existing group with no member
	 * certificates; 404 for an unknown group.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function members(string $gid): DataDisplayResponse {
		$headers = ['Content-Type' => 'text/plain; charset=utf-8'];
		$group = $this->groupManager->get($gid);
		if ($group === null) {
			return new DataDisplayResponse('', Http::STATUS_NOT_FOUND, $headers);
		}
		$dns = [];
		foreach ($group->getUsers() as $user) {
			$uid = $user->getUID();
			for ($i = 0; $i < self::MAX_DNS; $i++) {
				$dn = trim($this->config->getUserValue($uid, 'files_sharding', "x509_dn_{$i}", ''));
				if ($dn !== '' && !in_array($dn, $dns, true)) {
					$dns[] = $dn;
				}
			}
		}
		$body = $dns === [] ? '' : implode("\n", $dns) . "\n";
		return new DataDisplayResponse($body, Http::STATUS_OK, $headers);
	}
}

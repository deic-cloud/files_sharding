<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Controller;

use OCA\FilesSharding\Service\CertificateService;
use OCP\AppFramework\Controller;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Serves certificate and key file downloads for the logged-in user.
 * Plain (non-OCS) controller so we can return binary file responses.
 */
class X509Controller extends Controller {
	public function __construct(
		string                     $appName,
		IRequest                   $request,
		private CertificateService $certificateService,
		private IUserSession       $userSession,
		private IConfig            $config,
		private IAppConfig         $appConfig,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Whose cert/key to serve. Normally the logged-in user. As a trusted-caller
	 * path (the old chooser getkey.php): when the request is authenticated as the
	 * trusted user (user_pods 'trustedUser', e.g. "cloud", or the legacy
	 * 'vlantrusteduser') AND originates on the trusted VLAN, a $user query param
	 * selects any user's cert/key. Otherwise a foreign $user is refused ('').
	 */
	private function targetUserId(string $user): string {
		$current = $this->userSession->getUser()?->getUID() ?? '';
		if ($user === '' || $user === $current) {
			return $current;
		}
		$trustedUser = trim($this->appConfig->getValueString('user_pods', 'trustedUser', ''))
			?: trim($this->config->getSystemValue('vlantrusteduser', ''));
		// The trusted caller (e.g. 'cloud', which runs a batch-server POD on the
		// user-pod VLAN — e.g. 10.2.24.9 — and authenticates via its X.509 client
		// cert) may sit on EITHER trusted internal net: the pod VLAN (uservlannet,
		// 10.2., where the batch pod runs) or the infra net (trustednet, 10.0.).
		// The X.509 cert is the actual authorisation; this net check is only a
		// location belt-and-suspenders, so accept both.
		$ip = $this->request->getRemoteAddress();
		$onTrustedNet = $ip === '127.0.0.1' || $ip === '::1';
		foreach ([$this->config->getSystemValue('uservlannet', ''), $this->config->getSystemValue('trustednet', '')] as $n) {
			$n = trim((string)$n);
			if ($n !== '' && str_starts_with($ip, $n)) {
				$onTrustedNet = true;
				break;
			}
		}
		if ($current !== '' && $current === $trustedUser && $onTrustedNet) {
			return $user;
		}
		return '';
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function downloadCert(string $user = ''): Response {
		$userId = $this->targetUserId($user);
		$pem    = $this->certificateService->getCertPem($userId);
		if ($pem === '') {
			$r = new Response();
			$r->setStatus(404);
			return $r;
		}
		return new DataDownloadResponse($pem, 'usercert.pem', 'application/x-pem-file');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function downloadKey(string $user = ''): Response {
		$userId = $this->targetUserId($user);
		$pem    = $this->certificateService->getKeyPem($userId);
		if ($pem === '') {
			$r = new Response();
			$r->setStatus(404);
			return $r;
		}
		return new DataDownloadResponse($pem, 'userkey.pem', 'application/x-pem-file');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function downloadPkcs12(string $user = ''): Response {
		$userId = $this->targetUserId($user);
		$p12    = $this->certificateService->getPkcs12($userId);
		if ($p12 === '') {
			$r = new Response();
			$r->setStatus(404);
			return $r;
		}
		return new DataDownloadResponse($p12, 'usercert.p12', 'application/x-pkcs12');
	}
}

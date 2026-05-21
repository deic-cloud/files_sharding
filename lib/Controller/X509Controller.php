<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Controller;

use OCA\FilesSharding\Service\CertificateService;
use OCP\AppFramework\Controller;
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
	) {
		parent::__construct($appName, $request);
	}

	private function currentUserId(): string {
		return $this->userSession->getUser()?->getUID() ?? '';
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function downloadCert(): Response {
		$userId = $this->currentUserId();
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
	public function downloadKey(): Response {
		$userId = $this->currentUserId();
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
	public function downloadPkcs12(): Response {
		$userId = $this->currentUserId();
		$p12    = $this->certificateService->getPkcs12($userId);
		if ($p12 === '') {
			$r = new Response();
			$r->setStatus(404);
			return $r;
		}
		return new DataDownloadResponse($p12, 'usercert.p12', 'application/x-pkcs12');
	}
}

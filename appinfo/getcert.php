<?php

declare(strict_types=1);

/**
 * Legacy chooser-compatible endpoint: /remote.php/getcert?user=<user>
 *
 * Returns a user's PUBLIC certificate as {"data":{"certificate": <PEM>}} — the
 * shape the service pods expect (`curl .../remote.php/getcert?user=X | jq -r
 * .data.certificate`). A certificate is public, so this needs no auth; ?user
 * selects whose. Without ?user the caller asks for their OWN certificate: a
 * browser session, or — the case that matters — a service pod, which the
 * apache auth backends identify by its address on the pod VLAN (files_sharding
 * IpAuthBackend, the same mapping the data path and getkey.php use), else Basic
 * auth. For that caller a missing certificate is issued on the spot (2026-10-14,
 * Frederik): the owner's database pod needs its X.509 identity to start, and
 * on the old service getcert behaved this way too.
 *
 * Registered as the files_sharding <getcert> remote service; pod unchanged.
 */

$user = trim((string)($_GET['user'] ?? ''));
$self = false;
if ($user === '') {
	$userSession = \OCP\Server::get(\OCP\IUserSession::class);
	if ($userSession->getUser() === null) {
		\OC_User::handleApacheAuth();
	}
	if ($userSession->getUser() === null && method_exists($userSession, 'tryBasicAuthLogin')) {
		try {
			$userSession->tryBasicAuthLogin(\OCP\Server::get(\OCP\IRequest::class), \OCP\Server::get(\OCP\Security\Bruteforce\IThrottler::class));
		} catch (\Throwable) {
		}
	}
	$user = $userSession->getUser()?->getUID() ?? '';
	$self = $user !== '';
}
header('Content-Type: application/json; charset=utf-8');
if ($user === '') {
	http_response_code(400);
	echo json_encode(['status' => 'error', 'data' => ['message' => 'No user']]);
	return;
}
$certService = \OCP\Server::get(\OCA\FilesSharding\Service\CertificateService::class);
$pem = $certService->getCertPem($user);
if ($pem === '' && $self && $certService->generateCertificate($user) !== false) {
	$pem = $certService->getCertPem($user);
}
if ($pem === '') {
	http_response_code(404);
	echo json_encode(['status' => 'error', 'data' => ['message' => 'No certificate for user']]);
	return;
}
echo json_encode(['status' => 'success', 'data' => ['certificate' => $pem]]);

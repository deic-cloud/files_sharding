<?php

declare(strict_types=1);

/**
 * Legacy chooser-compatible endpoint: /remote.php/getkey
 *
 * Returns the AUTHENTICATED user's private key as {"data":{"private_key": <PEM>}}
 * — the exact shape the pdf_sign_sciencedata / customised-zenodo service pods
 * expect (they do `curl -u <user>: .../remote.php/getkey | jq -r .data.private_key`).
 *
 * "Authenticated user" is set by NC's base bootstrap before this file runs: for a
 * cloud-owned service pod the IpAuthBackend honours the Basic-auth username
 * (`-u <user>:`, empty password) on the pod VLAN → impersonation. So a trusted
 * pod fetches <user>'s key without exposing it to anyone else; a plain user gets
 * only their own. The private key never travels except to that gated caller.
 *
 * Registered as the files_sharding <getkey> remote service, so no rewrite is
 * needed and the pod stays byte-for-byte unchanged.
 */

$userSession = \OCP\Server::get(\OCP\IUserSession::class);
// A plain remote service does not auto-authenticate. Trigger the apache backends
// first (files_sharding IpAuthBackend → the cloud-pod `-u <user>:` impersonation;
// X509Backend), then fall back to Basic-auth password for ordinary clients.
if ($userSession->getUser() === null) {
	\OC_User::handleApacheAuth();
}
if ($userSession->getUser() === null) {
	$req = \OCP\Server::get(\OCP\IRequest::class);
	if (method_exists($userSession, 'tryBasicAuthLogin')) {
		try {
			$userSession->tryBasicAuthLogin($req, \OCP\Server::get(\OCP\Security\Bruteforce\IThrottler::class));
		} catch (\Throwable) {
		}
	}
}
$userId = $userSession->getUser()?->getUID() ?? '';
header('Content-Type: application/json; charset=utf-8');
if ($userId === '') {
	http_response_code(401);
	echo json_encode(['status' => 'error', 'data' => ['message' => 'Not authenticated']]);
	return;
}
$pem = \OCP\Server::get(\OCA\FilesSharding\Service\CertificateService::class)->getKeyPem($userId);
if ($pem === '') {
	http_response_code(404);
	echo json_encode(['status' => 'error', 'data' => ['message' => 'No key for user']]);
	return;
}
echo json_encode(['status' => 'success', 'data' => ['private_key' => $pem]]);

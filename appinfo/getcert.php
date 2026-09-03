<?php

declare(strict_types=1);

/**
 * Legacy chooser-compatible endpoint: /remote.php/getcert?user=<user>
 *
 * Returns a user's PUBLIC certificate as {"data":{"certificate": <PEM>}} — the
 * shape the service pods expect (`curl .../remote.php/getcert?user=X | jq -r
 * .data.certificate`). A certificate is public, so this needs no auth; ?user
 * selects whose. Falls back to the authenticated user when ?user is absent.
 *
 * Registered as the files_sharding <getcert> remote service; pod unchanged.
 */

$user = trim((string)($_GET['user'] ?? ''));
if ($user === '') {
	$user = \OC_User::getUser() ?: (\OCP\Server::get(\OCP\IUserSession::class)->getUser()?->getUID() ?? '');
}
header('Content-Type: application/json; charset=utf-8');
if ($user === '') {
	http_response_code(400);
	echo json_encode(['status' => 'error', 'data' => ['message' => 'No user']]);
	return;
}
$pem = \OCP\Server::get(\OCA\FilesSharding\Service\CertificateService::class)->getCertPem($user);
if ($pem === '') {
	http_response_code(404);
	echo json_encode(['status' => 'error', 'data' => ['message' => 'No certificate for user']]);
	return;
}
echo json_encode(['status' => 'success', 'data' => ['certificate' => $pem]]);

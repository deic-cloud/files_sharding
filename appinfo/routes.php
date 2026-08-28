<?php

declare(strict_types=1);

return [
	'routes' => [
		// Silo-side token exchange — browser lands here after master redirect
		['name' => 'login#exchange',     'url' => '/login',        'verb' => 'GET'],
		// Master-side post-login dispatcher — browser lands here (via redirect_url,
		// which survives the SAML/WAYF round-trip) and gets a fresh token + silo bounce
		['name' => 'login#dispatch',     'url' => '/dispatch',     'verb' => 'GET'],
		// Redirect to master login (for users who don't know their silo password)
		['name' => 'login#masterLogin',  'url' => '/master-login', 'verb' => 'GET'],
		// Master-side logout — "Back to login" link clears session before showing login form
		['name' => 'login#logout',       'url' => '/logout',       'verb' => 'GET'],
		// Inter-server calls (no NC session; gated by shared secret)
		['name' => 'internal#validateToken', 'url' => '/internal/token/validate',     'verb' => 'POST'],
		['name' => 'internal#issueToken',    'url' => '/internal/token',             'verb' => 'POST'],
		['name' => 'internal#updateFree',    'url' => '/internal/servers/{id}/free', 'verb' => 'POST'],
		['name' => 'internal#listServers',        'url' => '/internal/servers',                      'verb' => 'GET'],
		['name' => 'internal#searchUsers',        'url' => '/internal/users/search',                 'verb' => 'GET'],
		['name' => 'internal#exportExternalShares','url' => '/internal/users/{userId}/external-shares','verb' => 'GET'],
		['name' => 'internal#proxyShareAccept',    'url' => '/internal/shares/proxy-accept',           'verb' => 'POST'],
		// Share-authority reconcile backstop: master asks an owner silo which share ids are still live
		['name' => 'internal#liveShareIds',        'url' => '/internal/shares/live-ids',               'verb' => 'POST'],
		// Group fan-out (model A): residency resolution (master) + reconcile (any node)
		['name' => 'internal#groupRemoteMembers',  'url' => '/internal/group-remote-members',          'verb' => 'POST'],
		['name' => 'internal#groupShareReconcile', 'url' => '/internal/group-share-reconcile',         'verb' => 'POST'],
		['name' => 'internal#syncShares',          'url' => '/internal/users/{userId}/sync-shares',    'verb' => 'POST'],
		['name' => 'internal#updateUser',          'url' => '/internal/users/{userId}/update',          'verb' => 'POST'],
		['name' => 'internal#setPasswordHash',     'url' => '/internal/users/{userId}/pwhash',          'verb' => 'POST'],
		['name' => 'internal#deleteUser',          'url' => '/internal/users/{userId}/delete',          'verb' => 'POST'],
		// Certificate downloads (binary responses; session-authenticated)
		['name' => 'x509#downloadCert',   'url' => '/x509/cert',   'verb' => 'GET'],
		['name' => 'x509#downloadKey',    'url' => '/x509/key',    'verb' => 'GET'],
		['name' => 'x509#downloadPkcs12', 'url' => '/x509/pkcs12', 'verb' => 'GET'],
		// VO membership: group -> newline-separated member X.509 DNs (batch/GridFactory).
		// The silo Apache config rewrites /vos/<group> to this route.
		['name' => 'vo#members', 'url' => '/vos/{gid}', 'verb' => 'GET'],
		// Master-login sudo confirmation (browser redirect flow; session-authenticated)
		['name' => 'login#sudoInitiate', 'url' => '/sudo/initiate', 'verb' => 'GET'],
		['name' => 'login#sudoConfirm',  'url' => '/sudo/confirm',  'verb' => 'GET'],
		['name' => 'login#sudoCallback', 'url' => '/sudo/callback', 'verb' => 'GET'],
	],
	'ocs' => [
		// Servers
		// Rename a public link's token to a user-chosen name (old-service parity)
		['name' => 'api#setLinkName',  'url' => '/api/v1/link-name',        'verb' => 'PUT'],
		// One-click mount of a public link into the visitor's own files
		['name' => 'api#saveShare',    'url' => '/api/v1/save-share',       'verb' => 'POST'],
		['name' => 'api#getServers',   'url' => '/api/v1/servers',          'verb' => 'GET'],
		['name' => 'api#addServer',    'url' => '/api/v1/servers',          'verb' => 'POST'],
		['name' => 'api#updateServer', 'url' => '/api/v1/servers/{id}',     'verb' => 'PUT'],
		['name' => 'api#deleteServer', 'url' => '/api/v1/servers/{id}',     'verb' => 'DELETE'],
		// updateFree is handled by InternalController (plain, public, shared-secret)
		// ['name' => 'api#updateFree', ...]  ← removed; use /internal/servers/{id}/free

		// User→silo assignment
		['name' => 'api#listUsers',      'url' => '/api/v1/users',                 'verb' => 'GET'],
		['name' => 'api#getUserServer',  'url' => '/api/v1/users/{userId}/server', 'verb' => 'GET'],
		['name' => 'api#setUserServer',  'url' => '/api/v1/users/{userId}/server', 'verb' => 'PUT'],
		['name' => 'api#getUserAccess',  'url' => '/api/v1/users/{userId}/access', 'verb' => 'GET'],
		['name' => 'api#setUserAccess',  'url' => '/api/v1/users/{userId}/access', 'verb' => 'PUT'],

		// Token endpoints moved to InternalController (plain routes above)

		// Federation proxy — master proxies OCS share requests to the correct silo
		['name' => 'api#federationLookup', 'url' => '/api/v1/federation/user', 'verb' => 'GET'],

		// Folder visibility rules
		['name' => 'api#getFolders',    'url' => '/api/v1/folders',        'verb' => 'GET'],
		['name' => 'api#addFolder',     'url' => '/api/v1/folders',        'verb' => 'POST'],
		['name' => 'api#updateFolder',  'url' => '/api/v1/folders/{id}',   'verb' => 'PUT'],
		['name' => 'api#deleteFolder',  'url' => '/api/v1/folders/{id}',   'verb' => 'DELETE'],

		// X.509 client certificate DNs (personal)
		['name' => 'api#getX509Dns',   'url' => '/api/v1/x509',           'verb' => 'GET'],
		['name' => 'api#addX509Dn',    'url' => '/api/v1/x509',           'verb' => 'POST'],
		// Certificate generation — specific paths before the {index} wildcard
		['name' => 'api#generateCert', 'url' => '/api/v1/x509/generate',  'verb' => 'POST'],
		['name' => 'api#getCertInfo',  'url' => '/api/v1/x509/certinfo',  'verb' => 'GET'],
		['name' => 'api#deleteCertKey','url' => '/api/v1/x509/certkey',   'verb' => 'DELETE'],
		['name' => 'api#deleteX509Dn', 'url' => '/api/v1/x509/{index}',   'verb' => 'DELETE'],
		// Sudo status / token (read-only; for JS status display and auto-fill)
		['name' => 'api#sudoStatus', 'url' => '/api/v1/sudo/status', 'verb' => 'GET'],
		['name' => 'api#sudoToken',  'url' => '/api/v1/sudo/token',  'verb' => 'GET'],
		// Group-share fan-out child ids to hide in the sidebar (js/group-share-hide.js)
		['name' => 'api#groupFanoutShares', 'url' => '/api/v1/group-fanout-shares', 'verb' => 'GET'],
	],
];

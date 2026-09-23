<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Middleware;

use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IConfig;

/**
 * An empty share-dialog search field should show nothing.
 *
 * Before anything is typed, the dialog shows "recommendations": people recently
 * shared with. On a cluster where one person can hold several accounts whose
 * names are e-mail addresses, that list is where the wrong account gets picked
 * — the entry carries a display name and an avatar, and two accounts of the
 * same person look identical. With `sharing.show_recommendations` set to false
 * in the config file, the recommendations endpoint answers with nothing, so the
 * dropdown stays empty until the user types (how many characters is
 * `sharing.minSearchStringLength`, a core setting: the dialog searches once the
 * query is LONGER than it, so 1 means "from two characters").
 *
 * Default true: stock behaviour unless a deployment opts out.
 */
class ShareeRecommendationsMiddleware extends Middleware {
	public function __construct(
		private IConfig $config,
	) {
	}

	public function afterController($controller, $methodName, Response $response): Response {
		if ($methodName !== 'findRecommended'
			|| !is_a($controller, 'OCA\\Files_Sharing\\Controller\\ShareesAPIController')) {
			return $response;
		}
		if ($this->config->getSystemValueBool('sharing.show_recommendations', true)) {
			return $response;
		}
		$empty = ['users' => [], 'groups' => [], 'remotes' => [], 'remote_groups' => [], 'emails' => [],
			'circles' => [], 'rooms' => [], 'lookup' => [], 'lookupEnabled' => false,
			'exact' => ['users' => [], 'groups' => [], 'remotes' => [], 'remote_groups' => [], 'emails' => [],
				'circles' => [], 'rooms' => [], 'lookup' => []]];
		// Empty what comes back rather than replacing it: by the time this runs,
		// core's OCS middleware may already have wrapped the controller's answer in
		// its envelope, and a fresh DataResponse would go out with an empty body.
		if ($response instanceof DataResponse) {
			$response->setData($empty);
			return $response;
		}
		if (is_a($response, 'OC\\AppFramework\\OCS\\BaseResponse')) {
			$data = new \ReflectionProperty('OC\\AppFramework\\OCS\\BaseResponse', 'data');
			$data->setValue($response, $empty);
		}
		return $response;
	}
}

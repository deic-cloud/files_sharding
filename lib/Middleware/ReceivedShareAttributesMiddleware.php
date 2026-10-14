<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Middleware;

use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;

/**
 * The Files app's "Shared with you" view builds its rows from the share API's
 * entries (files_sharing SharingService.ts spreads each entry into the row's
 * attributes). Those entries say nothing about mounts, so the Files app cannot
 * tell that a row is the root of a received share, and its delete action is
 * labelled "Delete folder" instead of "Leave this share" (core deleteUtils.ts
 * decides on attributes 'is-mount-root' and 'mount-type' === 'shared'). The
 * action itself only unshares - a mount root is never deleted - but the label
 * frightens people away from a shared example. Seen with a public-link
 * collection added to an account, 2026-10-14.
 *
 * This adds the two attributes to every entry of the two "received shares"
 * answers: GET shares?shared_with_me=true and GET remote_shares.
 */
class ReceivedShareAttributesMiddleware extends Middleware {
	public function __construct(
		private IRequest $request,
	) {
	}

	public function afterController($controller, $methodName, Response $response): Response {
		if ($methodName !== 'getShares') {
			return $response;
		}
		if (is_a($controller, 'OCA\\Files_Sharing\\Controller\\ShareAPIController')) {
			$sharedWithMe = (string)$this->request->getParam('shared_with_me', 'false');
			if (!in_array(strtolower($sharedWithMe), ['true', '1', 'yes'], true)) {
				return $response;
			}
		} elseif (!is_a($controller, 'OCA\\Files_Sharing\\Controller\\RemoteController')) {
			return $response;
		}
		$data = $this->getData($response);
		if (!is_array($data)) {
			return $response;
		}
		$changed = false;
		foreach ($data as $k => $entry) {
			if (is_array($entry) && !array_key_exists('mount-type', $entry)) {
				$data[$k]['is-mount-root'] = true;
				$data[$k]['mount-type'] = 'shared';
				$changed = true;
			}
		}
		if ($changed) {
			$this->setData($response, $data);
		}
		return $response;
	}

	private function getData(Response $response): mixed {
		if ($response instanceof DataResponse) {
			return $response->getData();
		}
		if (is_a($response, 'OC\\AppFramework\\OCS\\BaseResponse')) {
			$p = new \ReflectionProperty('OC\\AppFramework\\OCS\\BaseResponse', 'data');
			return $p->getValue($response);
		}
		return null;
	}

	private function setData(Response $response, array $data): void {
		if ($response instanceof DataResponse) {
			$response->setData($data);
			return;
		}
		$p = new \ReflectionProperty('OC\\AppFramework\\OCS\\BaseResponse', 'data');
		$p->setValue($response, $data);
	}
}

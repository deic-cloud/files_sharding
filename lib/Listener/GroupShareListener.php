<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Listener;

use OCA\FilesSharding\Service\GroupShareRegistry;
use OCA\FilesSharding\Service\InterServerClient;
use OCA\FilesSharding\Service\ShardingService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\Share\Events\ShareDeletedEvent;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Cross-silo group sharing (Phase 1 — delivery). When a folder is shared with a
 * group (TYPE_GROUP), register it in the master's authoritative group-share
 * registry so the resolver can hand it to every member's silo, wherever they live.
 *
 * A group share has no NC access token, so we mint a companion LINK-share token
 * for the folder on the owner's node; a member's silo mounts the folder through
 * the owner's public-webdav endpoint with that token (same path as user federated
 * shares). The token stays server-side (registry + silo caches), never reaching a
 * user's browser, so removing a leaver's mount fully removes their access.
 *
 * @implements IEventListener<ShareCreatedEvent|ShareDeletedEvent>
 */
class GroupShareListener implements IEventListener {
	private const LABEL_PREFIX = 'files_sharding:group:';

	public function __construct(
		private ShardingService     $shardingService,
		private GroupShareRegistry  $registry,
		private InterServerClient   $client,
		private IShareManager       $shareManager,
		private IConfig             $config,
		private LoggerInterface     $logger,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof ShareCreatedEvent) {
			$this->onCreate($event->getShare());
		} elseif ($event instanceof ShareDeletedEvent) {
			$this->onDelete($event->getShare());
		}
	}

	private function onCreate(IShare $share): void {
		if ($share->getShareType() !== IShare::TYPE_GROUP) {
			return;
		}
		try {
			$gid   = (string)$share->getSharedWith();
			$owner = (string)($share->getShareOwner() ?: $share->getSharedBy());
			$node  = $share->getNode();
			$name  = '/' . ltrim($node->getName(), '/');
			$perms = $share->getPermissions();
			$ownerUrl = rtrim((string)$this->config->getSystemValue('overwrite.cli.url', ''), '/');
			if ($gid === '' || $owner === '' || $ownerUrl === '') {
				return;
			}

			// Mint a companion link-share token for the folder so member silos can
			// mount it via the owner's public-webdav endpoint. Labelled so we can
			// find/remove it on unshare (and hide it from the owner's UI later).
			$link = $this->shareManager->newShare();
			$link->setNode($node)
				->setShareType(IShare::TYPE_LINK)
				->setSharedBy((string)($share->getSharedBy() ?: $owner))
				->setPermissions($perms)
				->setLabel(self::LABEL_PREFIX . $gid);
			$link = $this->shareManager->createShare($link);
			$token  = (string)$link->getToken();
			$linkId = (string)$link->getId();

			if ($this->shardingService->isMaster()) {
				$this->registry->registerLocal($gid, $owner, $ownerUrl, $token, $name, $linkId, $perms);
			} else {
				$this->client->postDirect($this->shardingService->masterInternalUrl(), 'internal/group-shares/register', [
					'gid'         => $gid,
					'owner'       => $owner,
					'ownerUrl'    => $ownerUrl,
					'token'       => $token,
					'name'        => $name,
					'remoteId'    => $linkId,
					'permissions' => (string)$perms,
				]);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('files_sharding: GroupShareListener: register failed: ' . $e->getMessage());
		}
	}

	private function onDelete(IShare $share): void {
		if ($share->getShareType() !== IShare::TYPE_GROUP) {
			return;
		}
		try {
			$gid   = (string)$share->getSharedWith();
			$owner = (string)($share->getShareOwner() ?: $share->getSharedBy());
			$name  = '/' . ltrim($share->getNode()->getName(), '/');

			// Remove the companion link-share for this folder+group.
			try {
				foreach ($this->shareManager->getSharesBy($owner, IShare::TYPE_LINK, $share->getNode(), false, -1, 0) as $link) {
					if ($link->getLabel() === self::LABEL_PREFIX . $gid) {
						$this->shareManager->deleteShare($link);
					}
				}
			} catch (\Throwable $e) {
				$this->logger->warning('files_sharding: GroupShareListener: could not remove companion link: ' . $e->getMessage());
			}

			if ($this->shardingService->isMaster()) {
				$this->registry->deregisterLocal($gid, $owner, $name);
			} else {
				$this->client->postDirect($this->shardingService->masterInternalUrl(), 'internal/group-shares/deregister', [
					'gid'   => $gid,
					'owner' => $owner,
					'name'  => $name,
				]);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('files_sharding: GroupShareListener: deregister failed: ' . $e->getMessage());
		}
	}
}

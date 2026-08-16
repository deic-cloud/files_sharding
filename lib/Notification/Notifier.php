<?php

declare(strict_types=1);

namespace OCA\FilesSharding\Notification;

use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders the informational "X shared Y with you" notice that files_sharding
 * posts when a share is auto-accepted — local same-node shares (ShareCreatedListener)
 * and intra-cluster federated shares (ShareSyncService). It is ACTION-LESS: the
 * share is already accepted, so there is nothing to click, which also sidesteps
 * the stock notifications action-emit bug entirely (see project_notifications_emit_fix).
 */
class Notifier implements INotifier {
	public function __construct(
		private IFactory      $l10nFactory,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return 'files_sharding';
	}

	public function getName(): string {
		return 'ScienceData sharing';
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== 'files_sharding' || $notification->getSubject() !== 'share_received') {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get('files_sharding', $languageCode);
		$p = $notification->getSubjectParameters();
		$sharer = (string)($p[0] ?? '');
		$name   = (string)($p[1] ?? '');

		$notification->setParsedSubject($l->t('%1$s shared "%2$s" with you', [$sharer, $name]));
		$notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('core', 'actions/share.svg')));
		try {
			$notification->setLink($this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute('files.view.index')));
		} catch (\Throwable) {
			// files route unavailable in this context — leave the notice unlinked
		}
		return $notification;
	}
}

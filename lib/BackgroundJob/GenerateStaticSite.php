<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Collectives\BackgroundJob;

use OCA\Collectives\AppInfo\Application;
use OCA\Collectives\Notification\Notifier;
use OCA\Collectives\Service\CollectiveUserSettingsService;
use OCA\Collectives\Service\StaticSiteService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Generates a static site outside the request worker.
 *
 * Enqueued by {@see \OCA\Collectives\Controller\StaticSiteController}. Gathers the
 * page data, delegates rendering to the external renderer service (see ssg/) and
 * notifies the user once the site is stored in their files.
 */
class GenerateStaticSite extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private StaticSiteService $service,
		private CollectiveUserSettingsService $userSettingsService,
		private INotificationManager $notificationManager,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		if (!is_array($argument)) {
			return;
		}

		$userId = isset($argument['userId']) ? (string)$argument['userId'] : '';
		$collectiveId = isset($argument['collectiveId']) ? (int)$argument['collectiveId'] : 0;
		$pageIds = array_map(intval(...), (array)($argument['pageIds'] ?? []));
		$title = isset($argument['title']) && $argument['title'] !== null ? (string)$argument['title'] : null;

		if ($userId === '' || $collectiveId === 0 || $pageIds === []) {
			$this->logger->warning('Skipping static site job with incomplete arguments', ['argument' => $argument]);
			return;
		}

		try {
			$result = $this->service->generateSite($userId, $collectiveId, $pageIds, $title);
			$this->rememberSiteUrl($userId, $collectiveId, $result['url']);
			$this->notify($userId, $collectiveId, Notifier::SUBJECT_GENERATED, [
				'title' => $title ?? 'Collectives',
				'pages' => $result['pages'],
				'url' => $result['url'],
			]);
		} catch (Throwable $e) {
			$this->logger->error('Failed to generate static site', ['exception' => $e]);
			$this->notify($userId, $collectiveId, Notifier::SUBJECT_FAILED, [
				'title' => $title ?? 'Collectives',
				'error' => $e->getMessage(),
			]);
		}
	}

	/**
	 * Keep the link on the collective so it stays reachable after the
	 * notification is gone.
	 */
	private function rememberSiteUrl(string $userId, int $collectiveId, string $url): void {
		try {
			$this->userSettingsService->setStaticSiteUrl($collectiveId, $userId, $url);
		} catch (Throwable $e) {
			$this->logger->warning('Failed to store static site URL', ['exception' => $e]);
		}
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function notify(string $userId, int $collectiveId, string $subject, array $params): void {
		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp(Application::APP_NAME)
				->setUser($userId)
				->setDateTime($this->time->getDateTime())
				->setObject('staticsite', $collectiveId . '-' . $this->time->getTime())
				->setSubject($subject, $params);
			$this->notificationManager->notify($notification);
		} catch (Throwable $e) {
			$this->logger->warning('Failed to send static site notification', ['exception' => $e]);
		}
	}
}

<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Collectives\Notification;

use OCA\Collectives\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Parses the notifications emitted by the static site background job.
 */
class Notifier implements INotifier {
	public const SUBJECT_GENERATED = 'static_site_generated';
	public const SUBJECT_FAILED = 'static_site_failed';

	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return Application::APP_NAME;
	}

	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_NAME)->t('Collectives');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_NAME) {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(Application::APP_NAME, $languageCode);
		$params = $notification->getSubjectParameters();
		$title = isset($params['title']) ? (string)$params['title'] : 'Collectives';

		$notification->setIcon($this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath(Application::APP_NAME, 'collectives.svg'),
		));

		switch ($notification->getSubject()) {
			case self::SUBJECT_GENERATED:
				$pages = isset($params['pages']) ? (int)$params['pages'] : 0;
				$path = isset($params['path']) ? (string)$params['path'] : '';

				$notification->setParsedSubject(
					$l->t('Static site for "%s" is ready', [$title]),
				);
				$notification->setParsedMessage(
					$l->n(
						'%n page was exported to %s.',
						'%n pages were exported to %s.',
						$pages,
						[$path],
					),
				);
				if ($path !== '') {
					$notification->setLink($this->urlGenerator->linkToRouteAbsolute(
						'files.view.index',
						['dir' => '/' . ltrim($path, '/')],
					));
				}

				return $notification;

			case self::SUBJECT_FAILED:
				$notification->setParsedSubject(
					$l->t('Static site generation for "%s" failed', [$title]),
				);
				if (isset($params['error']) && is_string($params['error']) && $params['error'] !== '') {
					$notification->setParsedMessage((string)$params['error']);
				}

				return $notification;

			default:
				throw new UnknownNotificationException();
		}
	}
}

<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Collectives\Controller;

use OCA\Collectives\BackgroundJob\GenerateStaticSite;
use OCA\Collectives\Service\StaticSiteService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCSController;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Queues static site generation. The actual rendering runs in a background job
 * that delegates to the external renderer service (see ssg/), so nothing is
 * rendered in the request worker.
 */
class StaticSiteController extends OCSController {
	use UserTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private StaticSiteService $service,
		private IJobList $jobList,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Queue rendering of the selected collective pages as a static site.
	 *
	 * The site is generated asynchronously and stored in the user's files; the
	 * user is notified when it is ready.
	 *
	 * @param int $collectiveId ID of the collective
	 * @param int[] $pageIds IDs of the pages to include
	 * @param string|null $title Optional title shown on the generated site
	 *
	 * @return DataResponse<Http::STATUS_OK, array{queued: bool}, array{}>
	 * @throws OCSException Renderer not configured or invalid request
	 *
	 * 200: Static site generation queued
	 */
	#[NoAdminRequired]
	public function create(int $collectiveId, array $pageIds = [], ?string $title = null): DataResponse {
		if (!$this->service->isConfigured()) {
			throw new OCSException('Static site renderer service is not configured', Http::STATUS_NOT_IMPLEMENTED);
		}

		$pageIds = array_values(array_filter(
			array_map(intval(...), $pageIds),
			static fn (int $id): bool => $id > 0,
		));
		if ($pageIds === []) {
			throw new OCSException('No pages selected', Http::STATUS_BAD_REQUEST);
		}

		try {
			$this->jobList->add(GenerateStaticSite::class, [
				'userId' => $this->getUid(),
				'collectiveId' => $collectiveId,
				'pageIds' => $pageIds,
				'title' => $title,
			]);
		} catch (\Throwable $e) {
			$this->logger->error('Failed to queue static site generation', ['exception' => $e]);
			throw new OCSException($e->getMessage(), Http::STATUS_INTERNAL_SERVER_ERROR, $e);
		}

		return new DataResponse(['queued' => true]);
	}
}

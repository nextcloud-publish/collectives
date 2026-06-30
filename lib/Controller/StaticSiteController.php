<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Collectives\Controller;

use OCA\Collectives\Service\StaticSiteService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Renders static sites from collectives via PHP CommonMark.
 */
class StaticSiteController extends OCSController {
	use UserTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private StaticSiteService $service,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Render the selected collective pages as a static site and store it in the user's files.
	 *
	 * @param int $collectiveId ID of the collective
	 * @param int[] $pageIds IDs of the pages to include
	 * @param string|null $title Optional title shown on the generated site
	 *
	 * @return DataResponse<Http::STATUS_OK, array{path: string, pages: int}, array{}>
	 * @throws OCSException Build or storage failed
	 *
	 * 200: Static site generated and stored
	 */
	#[NoAdminRequired]
	public function create(int $collectiveId, array $pageIds = [], ?string $title = null): DataResponse {
		try {
			return new DataResponse($this->service->generateSite($this->getUid(), $collectiveId, $pageIds, $title));
		} catch (\Throwable $e) {
			$this->logger->error('Failed to generate static site', ['exception' => $e]);
			throw new OCSException($e->getMessage(), Http::STATUS_INTERNAL_SERVER_ERROR, $e);
		}
	}
}

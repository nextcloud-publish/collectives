<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Collectives\Controller;

use OCA\Collectives\Db\Collective;
use OCA\Collectives\Fs\NodeHelper;
use OCA\Collectives\ResponseDefinitions;
use OCA\Collectives\Service\CircleExistsException;
use OCA\Collectives\Service\CollectiveService;
use OCA\Collectives\Service\NotFoundException;
use OCA\Collectives\Service\PageService;
use OCA\Collectives\Service\UnprocessableEntityException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Share\IManager as IShareManager;
use Psr\Log\LoggerInterface;
use Throwable;
use ZipArchive;

/**
 * Provides access to collectives.
 *
 * @psalm-import-type CollectivesCollective from ResponseDefinitions
 */
class CollectiveController extends OCSController {
	use OCSExceptionHelper;
	use UserTrait;

	private const ATTACHMENTS_DIR = '_attachments';

	/** @var array<string, bool> */
	private array $addedAttachments = [];

	public function __construct(
		string $AppName,
		IRequest $request,
		private CollectiveService $service,
		private PageService $pageService,
		private IUserSession $userSession,
		private IFactory $l10nFactory,
		private LoggerInterface $logger,
		private NodeHelper $nodeHelper,
		private IRootFolder $rootFolder,
		private IShareManager $shareManager,
		private IURLGenerator $urlGenerator,
		private ?string $userId,
	) {
		parent::__construct($AppName, $request);
	}

	private function getUserLang(): string {
		return $this->l10nFactory->getUserLanguage($this->userSession->getUser());
	}

	/**
	 * Get collectives
	 *
	 * @return DataResponse<Http::STATUS_OK, array{collectives: list<CollectivesCollective>}, array{}>
	 * @throws OCSNotFoundException Something not found
	 * @throws OCSForbiddenException Not permitted
	 *
	 * 200: Collectives returned
	 */
	#[NoAdminRequired]
	public function index(): DataResponse {
		$collectives = $this->handleErrorResponse(fn (): array => $this->service->getCollectivesWithShares($this->getUid()), $this->logger);
		return new DataResponse(['collectives' => $collectives]);
	}

	/**
	 * Create a collective
	 *
	 * @param string $name Name of the collective
	 * @param ?string $emoji Optional emoji
	 *
	 * @return DataResponse<Http::STATUS_OK, array{collective: CollectivesCollective, info: string}, array{}>
	 * @throws OCSBadRequestException Collective or team already exists
	 * @throws OCSNotFoundException Something not found
	 * @throws OCSForbiddenException Not permitted
	 *
	 * 200: Collective created
	 */
	#[NoAdminRequired]
	public function create(string $name, ?string $emoji = null): DataResponse {
		try {
			[$collective, $info] = $this->handleErrorResponse(function () use ($name, $emoji): array {
				[$collective, $info] = $this->service->createCollective(
					$this->getUid(),
					$this->getUserLang(),
					$name,
					$emoji,
				);
				return [$collective, $info];
			}, $this->logger);
		} catch (CircleExistsException|UnprocessableEntityException $e) {
			$this->logger->debug('Collectives app team exists error: ' . $e->getMessage(), ['exception' => $e]);
			throw new OCSBadRequestException($e->getMessage());
		}
		return new DataResponse(['collective' => $collective, 'info' => $info]);
	}

	/**
	 * Update an existing collective
	 *
	 * @param int $id ID of the collective
	 * @param ?string $emoji Optional emoji
	 *
	 * @return DataResponse<Http::STATUS_OK, array{collective: CollectivesCollective}, array{}>
	 * @throws OCSNotFoundException Collective not found
	 * @throws OCSForbiddenException Not permitted
	 *
	 * 200: Collective updated
	 */
	#[NoAdminRequired]
	public function update(int $id, ?string $emoji = null): DataResponse {
		$collective = $this->handleErrorResponse(fn (): Collective => $this->service->updateCollective(
			$id,
			$this->getUid(),
			$emoji
		), $this->logger);
		return new DataResponse(['collective' => $collective]);
	}

	/**
	 * Set edit level for an existing collective
	 *
	 * @param int $id ID of the collective
	 * @param int $level Edit level
	 *
	 * @return DataResponse<Http::STATUS_OK, array{collective: CollectivesCollective}, array{}>
	 * @throws OCSNotFoundException Collective not found
	 * @throws OCSForbiddenException Not permitted
	 *
	 * 200: Collective updated
	 */
	#[NoAdminRequired]
	public function editLevel(int $id, int $level): DataResponse {
		$collective = $this->handleErrorResponse(fn (): Collective => $this->service->setPermissionLevel(
			$id,
			$this->getUid(),
			$level,
			Collective::editPermissions
		), $this->logger);
		return new DataResponse(['collective' => $collective]);
	}

	/**
	 * Set share level for an existing collective
	 *
	 * @param int $id ID of the collective
	 * @param int $level Share level
	 *
	 * @return DataResponse<Http::STATUS_OK, array{collective: CollectivesCollective}, array{}>
	 * @throws OCSNotFoundException Collective not found
	 * @throws OCSForbiddenException Not permitted
	 *
	 * 200: Collective updated
	 */
	#[NoAdminRequired]
	public function shareLevel(int $id, int $level): DataResponse {
		$collective = $this->handleErrorResponse(fn (): Collective => $this->service->setPermissionLevel(
			$id,
			$this->getUid(),
			$level,
			Constants::PERMISSION_SHARE
		), $this->logger);
		return new DataResponse(['collective' => $collective]);
	}

	/**
	 * Set page mode for an existing collective
	 *
	 * @param int $id ID of the collective
	 * @param int $mode Page edit mode
	 *
	 * @return DataResponse<Http::STATUS_OK, array{collective: CollectivesCollective}, array{}>
	 * @throws OCSNotFoundException Collective not found
	 * @throws OCSForbiddenException Not permitted
	 *
	 * 200: Collective updated
	 */
	#[NoAdminRequired]
	public function pageMode(int $id, int $mode): DataResponse {
		$collective = $this->handleErrorResponse(fn (): Collective => $this->service->setPageMode(
			$id,
			$this->getUid(),
			$mode,
		), $this->logger);
		return new DataResponse(['collective' => $collective]);
	}

	/**
	 * Trash an existing collective
	 *
	 * @param int $id ID of the collective
	 *
	 * @return DataResponse<Http::STATUS_OK, array{collective: CollectivesCollective}, array{}>
	 * @throws OCSNotFoundException Collective not found
	 * @throws OCSForbiddenException Not permitted
	 *
	 * 200: Collective trashed
	 */
	#[NoAdminRequired]
	public function trash(int $id): DataResponse {
		$collective = $this->handleErrorResponse(fn (): Collective => $this->service->trashCollective($id, $this->getUid()), $this->logger);
		return new DataResponse(['collective' => $collective]);
	}

	/**
	 * Export all files of a collective as a zip archive
	 *
	 * @param int $id ID of the collective
	 *
	 * @throws OCSNotFoundException Collective not found
	 * @throws OCSForbiddenException Not permitted
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function export(int $id): StreamResponse {
		$folder = $this->handleErrorResponse(function () use ($id): Folder {
			try {
				return $this->pageService->getCollectiveFolder($id, $this->getUid());
			} catch (FilesNotFoundException $e) {
				throw new NotFoundException($e->getMessage());
			}
		}, $this->logger);

		$zipPath = tempnam(sys_get_temp_dir(), 'collective_export_');
		$zip = new ZipArchive();
		$zip->open($zipPath, ZipArchive::OVERWRITE);
		$this->addedAttachments = [];
		$this->addFolderToZip($zip, $folder, $this->getUid());
		$zip->close();

		$handle = fopen($zipPath, 'rb');
		unlink($zipPath);

		$response = new StreamResponse($handle);
		$response->addHeader('Content-Disposition', 'attachment; filename="' . $folder->getName() . '.zip"');
		$response->addHeader('Content-Type', 'application/zip');
		return $response;
	}

	private function addFolderToZip(ZipArchive $zip, Folder $folder, string $userId, string $path = ''): void {
		foreach ($folder->getDirectoryListing() as $node) {
			$nodePath = $path === '' ? $node->getName() : $path . '/' . $node->getName();
			if ($node instanceof Folder) {
				$zip->addEmptyDir($nodePath);
				$this->addFolderToZip($zip, $node, $userId, $nodePath);
			} elseif ($node instanceof File) {
				if (str_ends_with($node->getName(), '.md')) {
					$content = $this->rewriteAttachmentLinks($zip, $node->getContent(), $nodePath, $userId);
				} else {
					$content = $node->getContent();
				}
				$zip->addFromString($nodePath, $content);
			}
		}
	}

	/**
	 * Find links to files outside the collective folder (e.g. plain Nextcloud share links),
	 * download the linked files into the zip and rewrite the links to point to the local copy.
	 */
	private function rewriteAttachmentLinks(ZipArchive $zip, string $content, string $mdPath, string $userId): string {
		$pattern = '/(!?\[[^\]]*\]\()(https?:\/\/[^\s)]+)(\s+\([^)]*\)|\s+"[^"]*")?(\))/';

		$rewritten = preg_replace_callback($pattern, function (array $matches) use ($zip, $mdPath, $userId): string {
			$prefix = $matches[1];
			$url = $matches[2];
			$title = $matches[3] ?? '';
			$suffix = $matches[4];

			$file = $this->resolveExternalLinkToFile($url, $userId);
			if ($file === null) {
				return $matches[0];
			}

			try {
				$attachmentName = $this->addAttachmentToZip($zip, $file);
			} catch (Throwable $e) {
				$this->logger->debug('Collectives app export: failed to add attachment ' . $file->getName() . ': ' . $e->getMessage(), ['exception' => $e]);
				return $matches[0];
			}

			$relativePath = $this->relativePathToAttachments($mdPath) . rawurlencode($attachmentName);
			return $prefix . $relativePath . $title . $suffix;
		}, $content);

		return $rewritten ?? $content;
	}

	/**
	 * Resolve a URL to a file node if it points to a share (`/s/{token}`) or direct link
	 * (`/f/{fileId}`) on this Nextcloud instance. Returns null for anything else or unresolvable links.
	 */
	private function resolveExternalLinkToFile(string $url, string $userId): ?File {
		$parsed = parse_url($url);
		if (!isset($parsed['host'], $parsed['path'])) {
			return null;
		}

		$base = parse_url($this->urlGenerator->getBaseUrl());
		if (!isset($base['host']) || $parsed['host'] !== $base['host']) {
			return null;
		}

		if (preg_match('#^/(?:index\.php/)?s/([A-Za-z0-9]+)/?$#', $parsed['path'], $m)) {
			return $this->resolveShareToken($m[1]);
		}

		if (preg_match('#^/(?:index\.php/)?f/(\d+)/?$#', $parsed['path'], $m)) {
			return $this->resolveFileId((int)$m[1], $userId);
		}

		return null;
	}

	private function resolveShareToken(string $token): ?File {
		try {
			$node = $this->shareManager->getShareByToken($token)->getNode();
		} catch (Throwable) {
			return null;
		}

		return $node instanceof File ? $node : null;
	}

	private function resolveFileId(int $fileId, string $userId): ?File {
		try {
			$nodes = $this->rootFolder->getUserFolder($userId)->getById($fileId);
		} catch (Throwable) {
			return null;
		}

		$node = $nodes[0] ?? null;
		return $node instanceof File ? $node : null;
	}

	/**
	 * Add a resolved external file to the zip's attachments folder (once per file) and
	 * return the attachment's filename within that folder.
	 */
	private function addAttachmentToZip(ZipArchive $zip, File $file): string {
		$attachmentName = $file->getId() . '_' . $file->getName();
		$zipPath = self::ATTACHMENTS_DIR . '/' . $attachmentName;

		if (!isset($this->addedAttachments[$zipPath])) {
			$zip->addFromString($zipPath, $file->getContent());
			$this->addedAttachments[$zipPath] = true;
		}

		return $attachmentName;
	}

	private function relativePathToAttachments(string $mdPath): string {
		$depth = substr_count($mdPath, '/');
		return str_repeat('../', $depth) . self::ATTACHMENTS_DIR . '/';
	}
}

<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Collectives\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use ZipArchive;

/**
 * Orchestrates static site generation.
 *
 * The heavy rendering (Markdown -> HTML, layout, zipping) runs in a separate,
 * horizontally scalable renderer service (see ssg/). This service only:
 *   1. gathers the selected pages' Markdown and their attachments,
 *   2. ships them to the renderer via {@see StaticSiteRendererClient},
 *   3. extracts the returned ZIP into the user's Nextcloud files.
 *
 * It is invoked from a background job so no rendering happens in the request
 * worker.
 */
class StaticSiteService {
	/** Folder (in the user's files) where generated sites are stored. */
	private const OUTPUT_BASE_DIR = 'Collectives Static Sites';

	public function __construct(
		private IRootFolder $rootFolder,
		private PageService $pageService,
		private StaticSiteRendererClient $rendererClient,
	) {
	}

	public function isConfigured(): bool {
		return $this->rendererClient->isConfigured();
	}

	/**
	 * Render the selected pages as a static site and store it in the user's files.
	 *
	 * @param int[] $pageIds IDs of the pages to include
	 *
	 * @return array{path: string, pages: int} Path of the output folder and number of rendered pages
	 *
	 * @throws ServiceException
	 */
	public function generateSite(string $userId, int $collectiveId, array $pageIds, ?string $title = null): array {
		$title = ($title !== null && trim($title) !== '') ? trim($title) : 'Collectives';

		$payload = $this->buildPayload($userId, $collectiveId, $pageIds, $title);
		if ($payload['pages'] === []) {
			throw new ServiceException('None of the selected pages could be read');
		}

		$result = $this->rendererClient->render($payload);
		$path = $this->storeZip($userId, $result['zip'], $title);

		return ['path' => $path, 'pages' => $result['pages']];
	}

	/**
	 * @param int[] $pageIds
	 *
	 * @return array{title: string, user: string, pages: list<array{id: int, title: string, markdown: string}>, attachments: list<array{path: string, content: string}>}
	 */
	private function buildPayload(string $userId, int $collectiveId, array $pageIds, string $title): array {
		$pages = $this->loadMarkdownPages($collectiveId, $pageIds, $userId);

		/** @var array<string, true> $collected */
		$collected = [];
		/** @var list<array{path: string, content: string}> $attachments */
		$attachments = [];
		foreach ($pageIds as $pageId) {
			$this->collectAttachmentFolder($collectiveId, (int)$pageId, $userId, $attachments, $collected);
		}
		foreach ($pages as $page) {
			foreach ($this->collectReferencedAttachmentPageIds($page['markdown']) as $ownerPageId) {
				$this->collectAttachmentFolder($collectiveId, $ownerPageId, $userId, $attachments, $collected);
			}
		}

		return [
			'title' => $title,
			'user' => $userId,
			'pages' => $pages,
			'attachments' => $attachments,
		];
	}

	/**
	 * @param int[] $pageIds
	 *
	 * @return list<array{id: int, title: string, markdown: string}>
	 */
	private function loadMarkdownPages(int $collectiveId, array $pageIds, string $userId): array {
		$pages = [];
		foreach ($pageIds as $pageId) {
			$pageId = (int)$pageId;
			try {
				$pageInfo = $this->pageService->find($collectiveId, $pageId, $userId);
				$markdown = $this->pageService->getPageFile($collectiveId, $pageId, $userId)->getContent();
			} catch (\Throwable) {
				// Skip pages we cannot read (e.g. missing or no permission).
				continue;
			}
			if (!is_string($markdown)) {
				continue;
			}

			$displayTitle = $pageInfo->getTitle() ?: 'Untitled';
			if ($pageInfo->getEmoji()) {
				$displayTitle = $pageInfo->getEmoji() . ' ' . $displayTitle;
			}

			$pages[] = [
				'id' => $pageId,
				'title' => $displayTitle,
				'markdown' => $markdown,
			];
		}

		return $pages;
	}

	/**
	 * Collect the files of a page's `.attachments.<id>` folder as base64 payload entries.
	 *
	 * @param list<array{path: string, content: string}> $attachments
	 * @param array<string, true> $collected
	 */
	private function collectAttachmentFolder(
		int $collectiveId,
		int $pageId,
		string $userId,
		array &$attachments,
		array &$collected,
	): void {
		$folderName = '.attachments.' . $pageId;
		if (isset($collected[$folderName])) {
			return;
		}

		try {
			$pageFile = $this->pageService->getPageFile($collectiveId, $pageId, $userId);
			$parent = $pageFile->getParent();
			if (!$parent->nodeExists($folderName)) {
				return;
			}
			$folder = $parent->get($folderName);
			if (!$folder instanceof Folder) {
				return;
			}

			$this->collectFolder($folder, $folderName, $attachments);
			$collected[$folderName] = true;
		} catch (\Throwable) {
			// Skip attachment folders we cannot read.
		}
	}

	/**
	 * @param list<array{path: string, content: string}> $attachments
	 */
	private function collectFolder(Folder $folder, string $relativePath, array &$attachments): void {
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof File) {
				$content = $node->getContent();
				if (is_string($content)) {
					$attachments[] = [
						'path' => $relativePath . '/' . $node->getName(),
						'content' => base64_encode($content),
					];
				}
				continue;
			}
			if ($node instanceof Folder) {
				$this->collectFolder($node, $relativePath . '/' . $node->getName(), $attachments);
			}
		}
	}

	/**
	 * @return int[]
	 */
	private function collectReferencedAttachmentPageIds(string $markdown): array {
		if (!preg_match_all('#\.attachments\.(\d+)/#', $markdown, $matches)) {
			return [];
		}

		return array_map(intval(...), array_unique($matches[1]));
	}

	/**
	 * Extract the rendered site ZIP into the user's files.
	 *
	 * @return string Path of the output folder, relative to the user's files
	 *
	 * @throws ServiceException
	 */
	private function storeZip(string $userId, string $zipBytes, string $title): string {
		$tmpFile = tempnam(sys_get_temp_dir(), 'collectives-ssg-');
		if ($tmpFile === false) {
			throw new ServiceException('Could not create a temporary file for the static site');
		}

		$zip = new ZipArchive();
		try {
			file_put_contents($tmpFile, $zipBytes);
			if ($zip->open($tmpFile) !== true) {
				throw new ServiceException('The static site renderer returned an invalid archive');
			}

			$userFolder = $this->rootFolder->getUserFolder($userId);
			$baseFolder = $userFolder->nodeExists(self::OUTPUT_BASE_DIR)
				? $userFolder->get(self::OUTPUT_BASE_DIR)
				: $userFolder->newFolder(self::OUTPUT_BASE_DIR);
			if (!$baseFolder instanceof Folder) {
				throw new ServiceException('Output location is not a folder');
			}

			$targetName = $this->safeName($title) . '-' . date('Ymd-His');
			$targetFolder = $baseFolder->newFolder($targetName);

			$wroteIndex = false;
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$name = $zip->getNameIndex($i);
				if ($name === false) {
					continue;
				}
				$segments = $this->sanitizeEntry($name);
				if ($segments === null) {
					continue;
				}
				if (str_ends_with($name, '/')) {
					$this->getOrCreateFolder($targetFolder, $segments);
					continue;
				}

				$content = $zip->getFromIndex($i);
				if ($content === false) {
					continue;
				}
				$fileName = array_pop($segments);
				$parent = $this->getOrCreateFolder($targetFolder, $segments);
				$parent->newFile($fileName, $content);
				if ($segments === [] && $fileName === 'index.html') {
					$wroteIndex = true;
				}
			}

			if (!$wroteIndex) {
				throw new ServiceException('The static site archive did not contain an index page');
			}

			return $userFolder->getRelativePath($targetFolder->getPath()) ?? self::OUTPUT_BASE_DIR . '/' . $targetName;
		} catch (ServiceException $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw new ServiceException('Failed to store the static site: ' . $e->getMessage(), 0, $e);
		} finally {
			@$zip->close();
			@unlink($tmpFile);
		}
	}

	/**
	 * Split a ZIP entry name into safe path segments, rejecting traversal.
	 *
	 * @return list<string>|null
	 */
	private function sanitizeEntry(string $name): ?array {
		$name = str_replace('\\', '/', $name);
		$segments = [];
		foreach (explode('/', $name) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}
			if ($segment === '..') {
				return null;
			}
			$segments[] = $segment;
		}

		return $segments === [] ? null : $segments;
	}

	/**
	 * @param list<string> $segments
	 */
	private function getOrCreateFolder(Folder $base, array $segments): Folder {
		$folder = $base;
		foreach ($segments as $segment) {
			if ($folder->nodeExists($segment)) {
				$existing = $folder->get($segment);
				if ($existing instanceof Folder) {
					$folder = $existing;
					continue;
				}
			}
			$folder = $folder->newFolder($segment);
		}

		return $folder;
	}

	private function safeName(string $name): string {
		$clean = trim(preg_replace('/[^\p{L}\p{N} _-]+/u', '', $name) ?? '');
		return $clean !== '' ? $clean : 'Collectives';
	}
}

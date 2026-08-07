<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Collectives\Service;

use OCP\Files\File;
use OCP\Files\Folder;

/**
 * Orchestrates static site generation.
 *
 * The heavy rendering (Markdown -> HTML, layout) runs in a separate,
 * horizontally scalable renderer service (see ssg/). This service only:
 *   1. gathers the selected pages' Markdown and their attachments,
 *   2. ships them to the renderer via {@see StaticSiteRendererClient},
 *   3. returns the URL under which the renderer serves the finished site.
 *
 * It is invoked from a background job so no rendering happens in the request
 * worker.
 */
class StaticSiteService {
	public function __construct(
		private PageService $pageService,
		private StaticSiteRendererClient $rendererClient,
	) {
	}

	public function isConfigured(): bool {
		return $this->rendererClient->isConfigured();
	}

	/**
	 * Render the selected pages as a static site hosted by the renderer service.
	 *
	 * @param int[] $pageIds IDs of the pages to include
	 *
	 * @return array{url: string, pages: int} URL of the generated site and number of rendered pages
	 *
	 * @throws ServiceException
	 */
	public function generateSite(string $userId, int $collectiveId, array $pageIds, ?string $title = null): array {
		$title = ($title !== null && trim($title) !== '') ? trim($title) : 'Collectives';

		$payload = $this->buildPayload($userId, $collectiveId, $pageIds, $title);
		if ($payload['pages'] === []) {
			throw new ServiceException('None of the selected pages could be read');
		}

		return $this->rendererClient->render($payload);
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
}

<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Collectives\Service;

use OCA\Collectives\Fs\MarkdownHelper;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;

/**
 * Renders a static site from selected collective pages using PHP CommonMark.
 *
 * Flow:
 *   1. read the selected pages' Markdown and convert to HTML (league/commonmark)
 *   2. copy `.attachments.*` folders for the selected pages into the site bundle
 *   3. write index.html and per-page HTML into a temp directory
 *   4. store the generated site (a folder of HTML) in the user's Nextcloud files
 */
class StaticSiteService {
	/** Folder (in the user's files) where generated sites are stored. */
	private const OUTPUT_BASE_DIR = 'Collectives Static Sites';

	public function __construct(
		private IRootFolder $rootFolder,
		private PageService $pageService,
		private StaticSiteRenderer $renderer,
	) {
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

		$workBase = sys_get_temp_dir() . '/collectives-ssg-' . bin2hex(random_bytes(6));
		$outDir = $workBase . '/out';

		try {
			$markdownPages = $this->loadMarkdownPages($collectiveId, $pageIds, $userId);
			if ($markdownPages === []) {
				throw new ServiceException('None of the selected pages could be read');
			}

			@mkdir($outDir, 0o700, true);

			$copiedAttachments = [];
			foreach ($pageIds as $pageId) {
				$this->copyAttachmentFolder($collectiveId, (int)$pageId, $userId, $outDir, $copiedAttachments);
			}
			foreach ($markdownPages as $page) {
				foreach ($this->collectReferencedAttachmentPageIds($page['markdown']) as $ownerPageId) {
					$this->copyAttachmentFolder($collectiveId, $ownerPageId, $userId, $outDir, $copiedAttachments);
				}
			}

			$pages = $this->renderPages($markdownPages);

			$this->renderer->renderIndex($outDir, $pages, $title, $userId);
			foreach ($pages as $page) {
				$this->renderer->renderPage($outDir, $page, $title, $userId);
			}

			$path = $this->storeOutput($userId, $outDir, $title);

			return ['path' => $path, 'pages' => count($pages)];
		} finally {
			$this->removeDir($workBase);
		}
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
	 * @param list<array{id: int, title: string, markdown: string}> $markdownPages
	 *
	 * @return list<array{id: int, title: string, html: string, summary: string}>
	 */
	private function renderPages(array $markdownPages): array {
		$pages = [];
		foreach ($markdownPages as $page) {
			$html = MarkdownHelper::toHtml($page['markdown']);
			$html = $this->rewriteAttachmentUrls($html);

			$pages[] = [
				'id' => $page['id'],
				'title' => $page['title'],
				'html' => $html,
				'summary' => $this->makeSummary($html),
			];
		}

		return $pages;
	}

	/**
	 * @param array<string, true> $copied
	 */
	private function copyAttachmentFolder(
		int $collectiveId,
		int $pageId,
		string $userId,
		string $outDir,
		array &$copied,
	): void {
		$folderName = '.attachments.' . $pageId;
		if (isset($copied[$folderName])) {
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

			$this->copyFolderToDisk($folder, $outDir . '/' . $folderName);
			$copied[$folderName] = true;
		} catch (\Throwable) {
			// Skip attachment folders we cannot read.
		}
	}

	private function copyFolderToDisk(Folder $folder, string $localPath): void {
		@mkdir($localPath, 0o700, true);
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof File) {
				$content = $node->getContent();
				if (is_string($content)) {
					file_put_contents($localPath . '/' . $node->getName(), $content);
				}
				continue;
			}
			if ($node instanceof Folder) {
				$this->copyFolderToDisk($node, $localPath . '/' . $node->getName());
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
	 * Pages live in page-<id>/index.html; attachment folders sit at the site root.
	 */
	private function rewriteAttachmentUrls(string $html): string {
		return preg_replace(
			'#(?<!\.\./)(?:\./)?\.attachments\.(\d+/)#',
			'../.attachments.$1',
			$html,
		) ?? $html;
	}

	private function makeSummary(string $html): string {
		$text = trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');
		if ($text === '') {
			return '';
		}
		if (mb_strlen($text) <= 160) {
			return $text;
		}

		return mb_substr($text, 0, 157) . '…';
	}

	/**
	 * Copy the generated site (a folder of HTML) into the user's files.
	 *
	 * @return string Path of the output folder, relative to the user's files
	 *
	 * @throws ServiceException
	 */
	private function storeOutput(string $userId, string $localOutDir, string $title): string {
		if (!is_dir($localOutDir) || !is_file($localOutDir . '/index.html')) {
			throw new ServiceException('Static site generation did not produce any output');
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$baseFolder = $userFolder->nodeExists(self::OUTPUT_BASE_DIR)
				? $userFolder->get(self::OUTPUT_BASE_DIR)
				: $userFolder->newFolder(self::OUTPUT_BASE_DIR);
			if (!$baseFolder instanceof Folder) {
				throw new ServiceException('Output location is not a folder');
			}

			$targetName = $this->safeName($title) . '-' . date('Ymd-His');
			$targetFolder = $baseFolder->newFolder($targetName);
			$this->copyTreeToFolder($localOutDir, $targetFolder);

			return $userFolder->getRelativePath($targetFolder->getPath()) ?? self::OUTPUT_BASE_DIR . '/' . $targetName;
		} catch (ServiceException $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw new ServiceException('Failed to store the static site: ' . $e->getMessage(), 0, $e);
		}
	}

	private function copyTreeToFolder(string $localDir, Folder $target): void {
		foreach (scandir($localDir) ?: [] as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$localPath = $localDir . '/' . $item;
			if (is_dir($localPath)) {
				$this->copyTreeToFolder($localPath, $target->newFolder($item));
				continue;
			}
			$content = file_get_contents($localPath);
			if ($content !== false) {
				$target->newFile($item, $content);
			}
		}
	}

	private function safeName(string $name): string {
		$clean = trim(preg_replace('/[^\p{L}\p{N} _-]+/u', '', $name) ?? '');
		return $clean !== '' ? $clean : 'Collectives';
	}

	private function removeDir(string $path): void {
		if (!is_dir($path)) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach ($items as $item) {
			$item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
		}
		@rmdir($path);
	}
}

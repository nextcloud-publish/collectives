<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Collectives\Service;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;

/**
 * Renders a static site from selected collective pages using the Hugo SSG.
 *
 * Flow:
 *   1. write the selected pages' Markdown into a temp copy of the Hugo project
 *   2. run the Hugo binary (a syscall) to render Markdown -> HTML
 *   3. store the generated site (a folder of HTML) in the user's Nextcloud files
 */
class StaticSiteService {
	/** Portable Hugo binary, relative to the app root (see ssg/fetch-hugo.sh). */
	private const HUGO_BINARY = 'ssg/.runtime/hugo';
	/** The Hugo project directory, relative to the app root. */
	private const HUGO_DIR = 'ssg/hugo';
	/** Folder (in the user's files) where generated sites are stored. */
	private const OUTPUT_BASE_DIR = 'Collectives Static Sites';

	public function __construct(
		private IRootFolder $rootFolder,
		private PageService $pageService,
	) {
	}

	/**
	 * Render the selected pages as a static site and store it in the user's files.
	 *
	 * @param int[] $pageIds IDs of the pages to include
	 *
	 * @return array{path: string, pages: int} Path of the output folder and number of rendered pages
	 *
	 * @throws MissingDependencyException
	 * @throws ServiceException
	 */
	public function generateSite(string $userId, int $collectiveId, array $pageIds, ?string $title = null): array {
		$appRoot = dirname(__DIR__, 2);
		$hugo = $appRoot . '/' . self::HUGO_BINARY;
		if (!is_executable($hugo)) {
			throw new MissingDependencyException('Hugo binary not found. Run `make ssg-setup` to install it.');
		}

		$title = ($title !== null && trim($title) !== '') ? trim($title) : 'Collectives';

		$workBase = sys_get_temp_dir() . '/collectives-ssg-' . bin2hex(random_bytes(6));
		$siteDir = $workBase . '/site';
		$outDir = $workBase . '/out';

		try {
			// Build from a private copy so the app directory stays read-only.
			$this->copyLocalTree($appRoot . '/' . self::HUGO_DIR, $siteDir);
			$rendered = $this->writePages($siteDir . '/content', $collectiveId, $pageIds, $userId);
			if ($rendered === 0) {
				throw new ServiceException('None of the selected pages could be read');
			}

			$this->runHugo($hugo, $siteDir, $outDir, $userId, $title);
			$path = $this->storeOutput($userId, $outDir, $title);

			return ['path' => $path, 'pages' => $rendered];
		} finally {
			$this->removeDir($workBase);
		}
	}

	/**
	 * Write the selected pages as Hugo Markdown content files.
	 *
	 * @param int[] $pageIds
	 *
	 * @return int Number of pages successfully written
	 */
	private function writePages(string $contentDir, int $collectiveId, array $pageIds, string $userId): int {
		@mkdir($contentDir, 0o700, true);

		$weight = 0;
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

			$weight++;
			$displayTitle = $pageInfo->getTitle() ?: 'Untitled';
			if ($pageInfo->getEmoji()) {
				$displayTitle = $pageInfo->getEmoji() . ' ' . $displayTitle;
			}

			$frontMatter = "---\n"
				. 'title: ' . $this->yamlString($displayTitle) . "\n"
				. 'weight: ' . $weight . "\n"
				. "---\n\n";
			file_put_contents($contentDir . '/page-' . $pageId . '.md', $frontMatter . $markdown);
		}

		return $weight;
	}

	/**
	 * Run the Hugo binary to build the site into $outDir.
	 *
	 * @throws ServiceException on a failed build
	 */
	private function runHugo(string $hugo, string $sourceDir, string $outDir, string $userId, string $title): void {
		$command = [$hugo, '--source', $sourceDir, '--destination', $outDir, '--noBuildLock', '--quiet'];
		$env = [
			'PATH' => '/usr/bin:/bin',
			'HOME' => sys_get_temp_dir(),
			// Injected into the Hugo templates (read there via os.Getenv).
			'COLLECTIVES_SSG_TITLE' => $title,
			'COLLECTIVES_SSG_USER' => $userId,
		];

		$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$process = proc_open($command, $descriptors, $pipes, $sourceDir, $env);
		if (!is_resource($process)) {
			throw new ServiceException('Could not start the Hugo process');
		}

		stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);

		if (proc_close($process) !== 0) {
			throw new ServiceException('Hugo build failed: ' . trim($error));
		}
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
			throw new ServiceException('Hugo did not produce any output');
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

	private function copyLocalTree(string $source, string $destination): void {
		@mkdir($destination, 0o700, true);
		foreach (scandir($source) ?: [] as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$sourcePath = $source . '/' . $item;
			$destinationPath = $destination . '/' . $item;
			is_dir($sourcePath)
				? $this->copyLocalTree($sourcePath, $destinationPath)
				: copy($sourcePath, $destinationPath);
		}
	}

	private function yamlString(string $value): string {
		return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
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

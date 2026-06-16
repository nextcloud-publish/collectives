<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Collectives\Service;

use OCA\Collectives\Fs\NodeHelper;
use OCA\Collectives\Model\PageInfo;
use OCA\Collectives\Service\Hugo\HugoSiteScaffold;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\ITempManager;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * Proof-of-concept: export collective pages as a Hugo-built static HTML zip.
 */
class CollectiveExportService {
	public function __construct(
		private readonly CollectiveService $collectiveService,
		private readonly PageService $pageService,
		private readonly NodeHelper $nodeHelper,
		private readonly HugoSiteScaffold $hugoScaffold,
		private readonly HugoService $hugoService,
		private readonly ITempManager $tempManager,
	) {
	}

	/**
	 * @return array{0: string, 1: string} Path to zip file and suggested download filename
	 *
	 * @throws MissingDependencyException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function createStaticSiteZip(int $collectiveId, int $pageId, string $userId): array {
		$collective = $this->collectiveService->getCollective($collectiveId, $userId);
		$pageInfo = $this->pageService->find($collectiveId, $pageId, $userId);
		$pageFile = $this->pageService->getPageFile($collectiveId, $pageId, $userId);

		$title = $pageInfo->getTitle() !== '' ? $pageInfo->getTitle() : $collective->getName();
		$siteDir = $this->tempManager->getTemporaryFolder('collectives-hugo');
		if ($siteDir === false) {
			throw new NotPermittedException('Failed to create temporary directory for collective export');
		}
		$siteDir = rtrim($siteDir, '/');

		$this->hugoScaffold->prepare($siteDir, $title);
		$contentDir = $siteDir . '/content';

		if (NodeHelper::isLandingPage($pageFile)) {
			$root = $this->pageService->getCollectiveFolder($collectiveId, $userId);
			$this->writeFolder($root, $contentDir, $collective->getName());
		} elseif (NodeHelper::isIndexPage($pageFile)) {
			$root = $pageFile->getParent();
			if (!($root instanceof Folder)) {
				throw new NotFoundException('Failed to get folder for page ' . $pageId);
			}
			$this->writeFolder($root, $contentDir, $root->getName());
		} else {
			$this->writeLeafPage($pageFile, $contentDir, $title);
		}

		$publicDir = $this->hugoService->build($siteDir);
		$zipPath = $this->tempManager->getTemporaryFile('zip');
		if ($zipPath === false) {
			throw new NotPermittedException('Failed to create temporary file for collective export');
		}
		$this->zipDirectory($publicDir, $zipPath);

		return [$zipPath, $this->nodeHelper->sanitiseFilename($title, 'page') . '.zip'];
	}

	/** Recursively map a collective folder to Hugo content (Readme.md → _index.md, Title.md → flat pages). */
	private function writeFolder(Folder $folder, string $destDir, string $title): void {
		$this->mkdir($destDir);

		$indexFile = null;
		try {
			$nodes = $folder->getDirectoryListing();
		} catch (FilesNotFoundException) {
			$nodes = [];
		}

		foreach ($nodes as $node) {
			if ($node instanceof File && NodeHelper::isIndexPage($node)) {
				$indexFile = $node;
				break;
			}
		}

		$body = $indexFile instanceof File ? $this->readContent($indexFile) : '';
		$this->writePage($destDir . '/_index.md', $title, $body);

		foreach ($nodes as $node) {
			if ($this->shouldSkip($node)) {
				continue;
			}
			if ($node instanceof File && NodeHelper::isPage($node) && !NodeHelper::isIndexPage($node)) {
				$name = basename($node->getName(), PageInfo::SUFFIX);
				$this->writePage($destDir . '/' . $this->safeName($name) . '.md', $name, $this->readContent($node));
			}
			if ($node instanceof Folder) {
				$this->writeFolder($node, $destDir . '/' . $this->safeName($node->getName()), $node->getName());
			}
		}
	}

	private function writeLeafPage(File $pageFile, string $contentDir, string $title): void {
		$this->mkdir($contentDir);
		$this->writePage($contentDir . '/_index.md', $title, $this->readContent($pageFile));

		$parent = $pageFile->getParent();
		if (!($parent instanceof Folder)) {
			return;
		}

		$base = basename($pageFile->getName(), PageInfo::SUFFIX);
		if ($parent->nodeExists($base)) {
			$node = $parent->get($base);
			if ($node instanceof Folder) {
				$this->writeFolder($node, $contentDir . '/' . $this->safeName($base), $base);
			}
		}
	}

	private function readContent(File $file): string {
		try {
			return $this->nodeHelper->getContent($file);
		} catch (NotFoundException|NotPermittedException) {
			return '';
		}
	}

	private function writePage(string $path, string $title, string $body): void {
		$this->writeFile($path, "---\ntitle: " . json_encode($title, JSON_UNESCAPED_UNICODE) . "\n---\n\n" . $body);
	}

	private function shouldSkip(Node $node): bool {
		$name = $node->getName();
		return $name === TemplateService::TEMPLATE_FOLDER || str_starts_with($name, '.');
	}

	private function safeName(string $name): string {
		return $this->nodeHelper->sanitiseFilename($name, 'page');
	}

	private function mkdir(string $dir): void {
		if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
			throw new NotPermittedException('Failed to create directory: ' . $dir);
		}
	}

	private function writeFile(string $path, string $content): void {
		if (file_put_contents($path, $content) === false) {
			throw new NotPermittedException('Failed to write file: ' . $path);
		}
	}

	private function zipDirectory(string $sourceDir, string $zipPath): void {
		$zip = new ZipArchive();
		if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
			throw new NotPermittedException('Failed to create zip archive');
		}

		$sourceDir = rtrim($sourceDir, '/');
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
		);
		foreach ($iterator as $item) {
			$zip->addFile($item->getPathname(), $iterator->getSubPathName());
		}
		$zip->close();
	}
}

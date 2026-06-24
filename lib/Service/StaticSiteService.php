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
 * Minimal example of rendering a static site with the Astro SSG.
 *
 * The flow mirrors the Hugo variant for comparison:
 *   1. run Astro (a Node syscall) to build HTML into a temporary directory
 *   2. store the generated index.html in the user's Nextcloud files
 *
 * Compared to Hugo (a single static binary), Astro is heavier: it needs a Node
 * runtime plus its node_modules, and `astro build` always writes a `.astro`
 * types dir into the project. So `ssg/astro` must be writable by the web-server
 * user (handled by `make ssg-setup`) and the build caches/output are redirected
 * to the temp directory via environment variables.
 */
class StaticSiteService {
	/** Portable Node runtime, relative to the app root (see ssg/fetch-node.sh). */
	private const NODE_BINARY = 'ssg/.runtime/node/bin/node';
	/** The Astro project directory, relative to the app root. */
	private const ASTRO_DIR = 'ssg/astro';
	/** The Astro CLI entry point, relative to the app root. */
	private const ASTRO_ENTRY = 'ssg/astro/node_modules/astro/astro.js';
	/** Folder (in the user's files) where generated sites are stored. */
	private const OUTPUT_BASE_DIR = 'Collectives Static Sites';

	public function __construct(
		private IRootFolder $rootFolder,
	) {
	}

	/**
	 * Render the bundled sample site and store it in the user's files.
	 *
	 * @return array{path: string} Path of the generated file, relative to the user's files
	 *
	 * @throws MissingDependencyException
	 * @throws ServiceException
	 */
	public function generateSampleSite(string $userId, ?string $title = null): array {
		$appRoot = dirname(__DIR__, 2);
		$node = $appRoot . '/' . self::NODE_BINARY;
		$astroEntry = $appRoot . '/' . self::ASTRO_ENTRY;
		if (!is_executable($node) || !is_file($astroEntry)) {
			throw new MissingDependencyException('Astro is not installed. Run `make ssg-setup` to install it.');
		}

		$title = ($title !== null && trim($title) !== '') ? trim($title) : 'Collectives';
		$outDir = sys_get_temp_dir() . '/collectives-ssg-' . bin2hex(random_bytes(6));

		try {
			$this->runAstro($node, $astroEntry, $appRoot . '/' . self::ASTRO_DIR, $outDir, $userId, $title);
			return $this->storeHtml($userId, $outDir . '/index.html', $title);
		} finally {
			$this->removeDir($outDir);
		}
	}

	/**
	 * Run Astro to build the site into $outDir.
	 *
	 * @throws ServiceException on a failed build
	 */
	private function runAstro(string $node, string $astroEntry, string $sourceDir, string $outDir, string $userId, string $title): void {
		$command = [$node, $astroEntry, 'build'];
		$env = [
			'PATH' => dirname($node) . ':/usr/bin:/bin',
			'HOME' => sys_get_temp_dir(),
			'ASTRO_TELEMETRY_DISABLED' => '1',
			// Astro can't write into node_modules as www-data, so redirect output + caches.
			'COLLECTIVES_SSG_OUTDIR' => $outDir,
			'COLLECTIVES_SSG_CACHEDIR' => $outDir . '/.cache',
			'COLLECTIVES_SSG_VITE_CACHEDIR' => $outDir . '/.vite',
			// Injected into the Astro templates (read there via process.env).
			'COLLECTIVES_SSG_TITLE' => $title,
			'COLLECTIVES_SSG_USER' => $userId,
		];

		$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$process = proc_open($command, $descriptors, $pipes, $sourceDir, $env);
		if (!is_resource($process)) {
			throw new ServiceException('Could not start the Astro process');
		}

		stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);

		if (proc_close($process) !== 0) {
			throw new ServiceException('Astro build failed: ' . trim($error));
		}
	}

	/**
	 * Store the generated HTML as a file in the user's files.
	 *
	 * @return array{path: string}
	 *
	 * @throws ServiceException
	 */
	private function storeHtml(string $userId, string $htmlFile, string $title): array {
		$html = file_get_contents($htmlFile);
		if ($html === false) {
			throw new ServiceException('Astro did not produce any output');
		}

		$userFolder = $this->rootFolder->getUserFolder($userId);
		$baseFolder = $userFolder->nodeExists(self::OUTPUT_BASE_DIR)
			? $userFolder->get(self::OUTPUT_BASE_DIR)
			: $userFolder->newFolder(self::OUTPUT_BASE_DIR);
		if (!$baseFolder instanceof Folder) {
			throw new ServiceException('Output location is not a folder');
		}

		$name = $this->safeName($title) . '-' . date('Ymd-His') . '.html';
		$file = $baseFolder->newFile($name, $html);

		return ['path' => $userFolder->getRelativePath($file->getPath()) ?? self::OUTPUT_BASE_DIR . '/' . $name];
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

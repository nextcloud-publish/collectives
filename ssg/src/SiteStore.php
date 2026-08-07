<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Collectives\Ssg;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Keeps the rendered sites on disk and resolves request paths against them.
 *
 * Each site lives in its own directory named after an unguessable id, which
 * doubles as the capability to view it: anyone who knows the id can read the
 * site, nobody else can enumerate it.
 */
class SiteStore {
	private const ID_PATTERN = '/^[0-9a-f]{32}$/';

	private string $baseDir;

	public function __construct(?string $baseDir = null) {
		$base = $baseDir ?? (getenv('COLLECTIVES_SSG_DATA_DIR') ?: '/data/sites');
		$this->baseDir = rtrim($base, '/');
	}

	/**
	 * Create an empty directory for a new site and return its id.
	 */
	public function create(): string {
		$id = bin2hex(random_bytes(16));
		$dir = $this->directory($id);
		if (!@mkdir($dir, 0o755, true) && !is_dir($dir)) {
			throw new RuntimeException('Could not create the site directory ' . $dir);
		}

		return $id;
	}

	public function directory(string $id): string {
		return $this->baseDir . '/' . $id;
	}

	public function isValidId(string $id): bool {
		return preg_match(self::ID_PATTERN, $id) === 1;
	}

	/**
	 * Resolve a request path within a site.
	 *
	 * @return string|null Absolute path of the file, or null if it does not
	 *                     exist or would escape the site directory
	 */
	public function resolveFile(string $id, string $relativePath): ?string {
		if (!$this->isValidId($id)) {
			return null;
		}

		$root = realpath($this->directory($id));
		if ($root === false) {
			return null;
		}

		$relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
		if ($relativePath === '' || str_ends_with($relativePath, '/')) {
			$relativePath .= 'index.html';
		}

		// realpath() collapses `..`, so the prefix check below rejects traversal.
		$target = realpath($root . '/' . $relativePath);
		if ($target === false
			|| !is_file($target)
			|| !str_starts_with($target, $root . '/')) {
			return null;
		}

		return $target;
	}

	public function remove(string $id): void {
		if (!$this->isValidId($id)) {
			return;
		}

		$dir = $this->directory($id);
		if (!is_dir($dir)) {
			return;
		}

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach ($items as $item) {
			/** @var \SplFileInfo $item */
			$item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
		}
		@rmdir($dir);
	}
}

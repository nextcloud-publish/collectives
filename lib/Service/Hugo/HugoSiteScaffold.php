<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Collectives\Service\Hugo;

use OCA\Collectives\Service\NotPermittedException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Copies the embedded Hugo skeleton (config template + layouts) into a temp site directory. */
class HugoSiteScaffold {
	private const SKELETON_DIR = __DIR__ . '/skeleton';

	/**
	 * @throws NotPermittedException
	 */
	public function prepare(string $siteDir, string $title): void {
		$siteDir = rtrim($siteDir, '/');
		$this->copyDirectory(self::SKELETON_DIR . '/layouts', $siteDir . '/layouts');
		$this->mkdir($siteDir . '/content');
		$this->writeConfig($siteDir, $title);
	}

	/**
	 * @throws NotPermittedException
	 */
	private function writeConfig(string $siteDir, string $title): void {
		$templatePath = self::SKELETON_DIR . '/hugo.toml.template';
		$template = file_get_contents($templatePath);
		if ($template === false) {
			throw new NotPermittedException('Failed to read hugo config template');
		}

		$config = str_replace(
			['{{TITLE}}', '{{CACHE_DIR}}'],
			[$this->tomlString($title), $this->tomlString($siteDir . '/.hugo_cache')],
			$template,
		);

		if (file_put_contents($siteDir . '/hugo.toml', $config) === false) {
			throw new NotPermittedException('Failed to write hugo.toml');
		}
	}

	private function tomlString(string $value): string {
		return "'" . str_replace("'", '', $value) . "'";
	}

	/**
	 * @throws NotPermittedException
	 */
	private function copyDirectory(string $source, string $dest): void {
		$this->mkdir($dest);
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST,
		);
		foreach ($iterator as $item) {
			$target = $dest . '/' . $iterator->getSubPathName();
			if ($item->isDir()) {
				$this->mkdir($target);
			} elseif (copy($item->getPathname(), $target) === false) {
				throw new NotPermittedException('Failed to copy hugo skeleton: ' . $target);
			}
		}
	}

	/**
	 * @throws NotPermittedException
	 */
	private function mkdir(string $dir): void {
		if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
			throw new NotPermittedException('Failed to create directory: ' . $dir);
		}
	}
}

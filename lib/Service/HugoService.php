<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Collectives\Service;

use OCA\Collectives\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/** Proof-of-concept: run the hugo binary on a prepared site directory. */
class HugoService {
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @throws MissingDependencyException
	 */
	public function getBinaryPath(): string {
		$configured = $this->appConfig->getValueString(Application::APP_NAME, 'hugo_binary', '');
		if ($configured !== '') {
			if (!is_executable($configured)) {
				throw new MissingDependencyException('Configured hugo binary is not executable: ' . $configured);
			}
			return $configured;
		}

		foreach (['/usr/bin/hugo', '/usr/local/bin/hugo'] as $candidate) {
			if (is_executable($candidate)) {
				return $candidate;
			}
		}

		throw new MissingDependencyException(
			'Hugo not found. Install it in the container or set collectives → hugo_binary via occ.'
		);
	}

	/**
	 * @throws MissingDependencyException
	 * @throws NotPermittedException
	 */
	public function build(string $siteDir): string {
		$publicDir = rtrim($siteDir, '/') . '/public';
		$command = [
			$this->getBinaryPath(),
			'--source', $siteDir,
			'--destination', $publicDir,
			'--logLevel', 'warn',
			'--cleanDestinationDir',
		];

		$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $siteDir, [
			'HOME' => $siteDir,
			'TMPDIR' => $siteDir,
		]);
		if (!is_resource($process)) {
			throw new NotPermittedException('Failed to start hugo');
		}

		fclose($pipes[0]);
		$stdout = stream_get_contents($pipes[1]) ?: '';
		$stderr = stream_get_contents($pipes[2]) ?: '';
		fclose($pipes[1]);
		fclose($pipes[2]);

		if (proc_close($process) !== 0) {
			$this->logger->error('Hugo build failed', ['stdout' => $stdout, 'stderr' => $stderr]);
			$detail = trim($stderr) !== '' ? trim($stderr) : trim($stdout);
			throw new NotPermittedException('Hugo build failed' . ($detail !== '' ? ': ' . $detail : ''));
		}

		return $publicDir;
	}
}

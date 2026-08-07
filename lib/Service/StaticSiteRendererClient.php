<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Collectives\Service;

use OCA\Collectives\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Talks to the external static site renderer microservice (see ssg/).
 *
 * The renderer runs in a separate, horizontally scalable Docker container. This
 * client ships the page Markdown + attachments to it and returns the rendered
 * site as a ZIP archive, so no rendering happens inside the Nextcloud process.
 */
class StaticSiteRendererClient {
	public const CONFIG_URL = 'ssg_renderer_url';

	/** Rendering a large collective can take a while. */
	private const TIMEOUT = 600;

	public function __construct(
		private IClientService $clientService,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	public function isConfigured(): bool {
		return $this->getUrl() !== '';
	}

	private function getUrl(): string {
		return rtrim(trim($this->appConfig->getValueString(Application::APP_NAME, self::CONFIG_URL, '')), '/');
	}

	/**
	 * Render the given payload into a static site ZIP archive.
	 *
	 * @param array{title: string, user: string, pages: list<array{id: int, title: string, markdown: string}>, attachments: list<array{path: string, content: string}>} $payload
	 *
	 * @return array{zip: string, pages: int}
	 *
	 * @throws StaticSiteRendererException
	 */
	public function render(array $payload): array {
		$url = $this->getUrl();
		if ($url === '') {
			throw new StaticSiteRendererException('Static site renderer service is not configured');
		}

		$body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($body === false) {
			throw new StaticSiteRendererException('Failed to encode the renderer payload');
		}

		$client = $this->clientService->newClient();
		try {
			$response = $client->post($url . '/render', [
				'body' => $body,
				'headers' => [
					'Content-Type' => 'application/json',
					'Accept' => 'application/zip',
				],
				'timeout' => self::TIMEOUT,
				'http_errors' => false,
				// The renderer typically runs on an internal/local address.
				'nextcloud' => ['allow_local_address' => true],
			]);
		} catch (Throwable $e) {
			$this->logger->error('Static site renderer request failed', ['exception' => $e]);
			throw new StaticSiteRendererException('Could not reach the static site renderer service: ' . $e->getMessage(), 0, $e);
		}

		$status = $response->getStatusCode();
		$content = (string)$response->getBody();

		if ($status !== 200) {
			throw new StaticSiteRendererException('Static site renderer returned an error: ' . $this->extractError($content, $status));
		}
		if ($content === '') {
			throw new StaticSiteRendererException('Static site renderer returned an empty response');
		}

		return [
			'zip' => $content,
			'pages' => (int)($response->getHeader('X-Rendered-Pages') ?: 0),
		];
	}

	private function extractError(string $body, int $status): string {
		$decoded = json_decode($body, true);
		if (is_array($decoded) && isset($decoded['error']) && is_string($decoded['error'])) {
			return $decoded['error'];
		}

		return 'HTTP status ' . $status;
	}
}

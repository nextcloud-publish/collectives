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
 * client ships the page Markdown + attachments to it; the renderer stores the
 * rendered site and serves it itself, so the app only keeps the resulting URL.
 */
class StaticSiteRendererClient {
	public const CONFIG_URL = 'ssg_renderer_url';
	public const CONFIG_PUBLIC_URL = 'ssg_public_url';

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
	 * Base URL that browsers use to reach the renderer.
	 *
	 * Falls back to the internal URL, which differs whenever Nextcloud reaches
	 * the renderer on a container-only address.
	 */
	private function getPublicUrl(): string {
		$publicUrl = rtrim(trim($this->appConfig->getValueString(Application::APP_NAME, self::CONFIG_PUBLIC_URL, '')), '/');

		return $publicUrl !== '' ? $publicUrl : $this->getUrl();
	}

	/**
	 * Render the given payload into a site hosted by the renderer service.
	 *
	 * @param array{title: string, user: string, pages: list<array{id: int, title: string, markdown: string}>, attachments: list<array{path: string, content: string}>} $payload
	 *
	 * @return array{url: string, pages: int}
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
					'Accept' => 'application/json',
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

		if ($status !== 200 && $status !== 201) {
			throw new StaticSiteRendererException('Static site renderer returned an error: ' . $this->extractError($content, $status));
		}

		$decoded = json_decode($content, true);
		if (!is_array($decoded) || !isset($decoded['path']) || !is_string($decoded['path']) || $decoded['path'] === '') {
			throw new StaticSiteRendererException('Static site renderer returned an unexpected response');
		}

		return [
			'url' => $this->getPublicUrl() . '/' . ltrim($decoded['path'], '/'),
			'pages' => (int)($decoded['pages'] ?? 0),
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

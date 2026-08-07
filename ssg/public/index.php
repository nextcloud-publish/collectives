<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * HTTP entry point for the Collectives static site renderer service.
 *
 * Endpoints:
 *   GET  /health  -> { "status": "ok" }
 *   POST /render  -> ZIP archive of the rendered site (Content-Type: application/zip)
 */

use Collectives\Ssg\SiteRenderer;

require __DIR__ . '/../vendor/autoload.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

/**
 * @param array<string, mixed> $data
 */
function sendJson(int $status, array $data): void {
	http_response_code($status);
	header('Content-Type: application/json');
	echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

if ($method === 'GET' && $path === '/health') {
	sendJson(200, ['status' => 'ok']);
	return true;
}

if ($path !== '/render') {
	sendJson(404, ['error' => 'Not found']);
	return true;
}

if ($method !== 'POST') {
	header('Allow: POST');
	sendJson(405, ['error' => 'Method not allowed']);
	return true;
}

$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
	sendJson(400, ['error' => 'Invalid JSON payload']);
	return true;
}

try {
	$result = (new SiteRenderer())->build($payload);
} catch (Throwable $e) {
	error_log('Collectives SSG render failed: ' . $e->getMessage());
	sendJson(500, ['error' => $e->getMessage()]);
	return true;
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="collectives-site.zip"');
header('X-Rendered-Pages: ' . $result['pages']);
header('Content-Length: ' . strlen($result['zip']));
echo $result['zip'];

return true;

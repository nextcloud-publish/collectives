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
 *   GET  /health          -> { "status": "ok" }
 *   POST /render          -> { "id": "...", "path": "/sites/<id>/", "pages": 3 }
 *   GET  /sites/<id>/...  -> the rendered site
 */

use Collectives\Ssg\SiteRenderer;
use Collectives\Ssg\SiteStore;

require __DIR__ . '/../vendor/autoload.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$store = new SiteStore();

/**
 * @param array<string, mixed> $data
 */
function sendJson(int $status, array $data): void {
	http_response_code($status);
	header('Content-Type: application/json');
	echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function contentType(string $file): string {
	return match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
		'html', 'htm' => 'text/html; charset=utf-8',
		'css' => 'text/css; charset=utf-8',
		'js' => 'text/javascript; charset=utf-8',
		'json' => 'application/json',
		'svg' => 'image/svg+xml',
		'png' => 'image/png',
		'jpg', 'jpeg' => 'image/jpeg',
		'gif' => 'image/gif',
		'webp' => 'image/webp',
		'avif' => 'image/avif',
		'ico' => 'image/x-icon',
		'pdf' => 'application/pdf',
		'txt', 'md' => 'text/plain; charset=utf-8',
		'woff2' => 'font/woff2',
		'woff' => 'font/woff',
		default => 'application/octet-stream',
	};
}

if ($method === 'GET' && $path === '/health') {
	sendJson(200, ['status' => 'ok']);
	return true;
}

if (preg_match('#^/sites/([^/]+)(/.*)?$#', $path, $matches) === 1) {
	if ($method !== 'GET' && $method !== 'HEAD') {
		header('Allow: GET, HEAD');
		sendJson(405, ['error' => 'Method not allowed']);
		return true;
	}

	$id = $matches[1];
	if (!$store->isValidId($id)) {
		sendJson(404, ['error' => 'Not found']);
		return true;
	}

	// Without the trailing slash the relative links inside the site would
	// resolve against /sites/ instead of the site root.
	if (($matches[2] ?? '') === '') {
		http_response_code(301);
		header('Location: /sites/' . $id . '/');
		return true;
	}

	$file = $store->resolveFile($id, $matches[2]);
	if ($file === null) {
		sendJson(404, ['error' => 'Not found']);
		return true;
	}

	header('Content-Type: ' . contentType($file));
	header('Content-Length: ' . (string)filesize($file));
	if ($method !== 'HEAD') {
		readfile($file);
	}

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
	$id = $store->create();
} catch (Throwable $e) {
	error_log('Collectives SSG could not create a site: ' . $e->getMessage());
	sendJson(500, ['error' => $e->getMessage()]);
	return true;
}

try {
	$pages = (new SiteRenderer())->build($payload, $store->directory($id));
} catch (Throwable $e) {
	$store->remove($id);
	error_log('Collectives SSG render failed: ' . $e->getMessage());
	sendJson(500, ['error' => $e->getMessage()]);
	return true;
}

sendJson(201, [
	'id' => $id,
	'path' => '/sites/' . $id . '/',
	'pages' => $pages,
]);

return true;

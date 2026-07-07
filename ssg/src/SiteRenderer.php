<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Collectives\Ssg;

use RuntimeException;
use ZipArchive;

/**
 * Renders a static site (index + per-page HTML + attachments) and returns it as
 * a ZIP archive.
 *
 * Ports the rendering and layout that used to live in the Collectives app
 * (StaticSiteService + StaticSiteRenderer) into a standalone, horizontally
 * scalable service.
 */
class SiteRenderer {
	public function __construct(
		private MarkdownConverter $markdown = new MarkdownConverter(),
	) {
	}

	/**
	 * Build the static site from the request payload and return the ZIP bytes.
	 *
	 * Expected payload shape:
	 *   {
	 *     "title": "My collective",
	 *     "user": "alice",
	 *     "pages": [ { "id": 12, "title": "🚀 Home", "markdown": "..." } ],
	 *     "attachments": [ { "path": ".attachments.12/img.png", "content": "<base64>" } ]
	 *   }
	 *
	 * @param array<string, mixed> $payload
	 *
	 * @return array{zip: string, pages: int}
	 */
	public function build(array $payload): array {
		$title = isset($payload['title']) && is_string($payload['title']) && trim($payload['title']) !== ''
			? trim($payload['title'])
			: 'Collectives';
		$user = isset($payload['user']) && is_string($payload['user']) ? $payload['user'] : 'Collectives';
		$pagesInput = is_array($payload['pages'] ?? null) ? $payload['pages'] : [];
		$attachments = is_array($payload['attachments'] ?? null) ? $payload['attachments'] : [];

		if ($pagesInput === []) {
			throw new RuntimeException('No pages to render');
		}

		$workBase = sys_get_temp_dir() . '/collectives-ssg-' . bin2hex(random_bytes(6));
		$outDir = $workBase . '/out';

		try {
			if (!@mkdir($outDir, 0o700, true) && !is_dir($outDir)) {
				throw new RuntimeException('Could not create work directory');
			}

			$this->writeAttachments($attachments, $outDir);

			$pages = [];
			foreach ($pagesInput as $page) {
				if (!is_array($page) || !isset($page['id'], $page['markdown'])) {
					continue;
				}
				$html = $this->markdown->toHtml((string)$page['markdown']);
				$html = $this->rewriteAttachmentUrls($html);
				$pages[] = [
					'id' => (int)$page['id'],
					'title' => isset($page['title']) && is_string($page['title']) ? $page['title'] : 'Untitled',
					'html' => $html,
					'summary' => $this->makeSummary($html),
				];
			}

			$this->renderIndex($outDir, $pages, $title, $user);
			foreach ($pages as $page) {
				$this->renderPage($outDir, $page, $title, $user);
			}

			return [
				'zip' => $this->zipDirectory($outDir),
				'pages' => count($pages),
			];
		} finally {
			$this->removeDir($workBase);
		}
	}

	/**
	 * @param array<int, mixed> $attachments
	 */
	private function writeAttachments(array $attachments, string $outDir): void {
		foreach ($attachments as $attachment) {
			if (!is_array($attachment) || !isset($attachment['path'], $attachment['content'])) {
				continue;
			}
			$relative = $this->sanitizeRelativePath((string)$attachment['path']);
			if ($relative === null) {
				continue;
			}
			$content = base64_decode((string)$attachment['content'], true);
			if ($content === false) {
				continue;
			}

			$target = $outDir . '/' . $relative;
			$dir = dirname($target);
			if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
				continue;
			}
			file_put_contents($target, $content);
		}
	}

	/**
	 * Reject absolute paths and any traversal so a malicious payload cannot write
	 * outside the site bundle.
	 */
	private function sanitizeRelativePath(string $path): ?string {
		$path = str_replace('\\', '/', $path);
		$path = ltrim($path, '/');
		if ($path === '') {
			return null;
		}
		foreach (explode('/', $path) as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..') {
				return null;
			}
		}

		return $path;
	}

	/**
	 * @param list<array{id: int, title: string, summary: string}> $pages
	 */
	private function renderIndex(string $outDir, array $pages, string $title, string $userId): void {
		$cards = '';
		if ($pages === []) {
			$cards = '<article class="card"><p>No pages were selected for this static site.</p></article>';
		} else {
			foreach ($pages as $page) {
				$href = 'page-' . $page['id'] . '/';
				$cards .= '<article class="card">'
					. '<h2><a href="' . $this->e($href) . '">' . $this->e($page['title']) . '</a></h2>';
				if ($page['summary'] !== '') {
					$cards .= '<p>' . $this->e($page['summary']) . '</p>';
				}
				$cards .= '</article>';
			}
		}

		$main = '<div class="cards">' . $cards . '</div>';
		$html = $this->wrapLayout(
			$title,
			$title,
			'A static site generated from Nextcloud Collectives',
			$main,
			$userId,
		);
		file_put_contents($outDir . '/index.html', $html);
	}

	/**
	 * @param array{id: int, title: string, html: string} $page
	 */
	private function renderPage(string $outDir, array $page, string $siteTitle, string $userId): void {
		$dir = $outDir . '/page-' . $page['id'];
		@mkdir($dir, 0o700, true);

		$main = '<article class="page">'
			. '<a class="back" href="../">← Back to overview</a>'
			. '<div class="content">' . $page['html'] . '</div>'
			. '</article>';

		$html = $this->wrapLayout($page['title'], $siteTitle, $page['title'], $main, $userId);
		file_put_contents($dir . '/index.html', $html);
	}

	/**
	 * Pages live in page-<id>/index.html; attachment folders sit at the site root.
	 */
	private function rewriteAttachmentUrls(string $html): string {
		return preg_replace(
			'#(?<!\.\./)(?:\./)?\.attachments\.(\d+/)#',
			'../.attachments.$1',
			$html,
		) ?? $html;
	}

	private function makeSummary(string $html): string {
		$text = trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');
		if ($text === '') {
			return '';
		}
		if (mb_strlen($text) <= 160) {
			return $text;
		}

		return mb_substr($text, 0, 157) . '…';
	}

	private function wrapLayout(string $documentTitle, string $siteTitle, string $subtitle, string $main, string $userId): string {
		$title = $this->e($documentTitle);
		$heroTitle = $this->e($siteTitle);
		$subtitleEscaped = $this->e($subtitle);
		$user = $this->e($userId !== '' ? $userId : 'Collectives');
		$timestamp = gmdate('Y-m-d H:i:s');

		return <<<HTML
<!doctype html>
<html lang="en">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<meta name="generator" content="Nextcloud Collectives">
		<title>{$title}</title>
		<style>
			:root { color-scheme: light dark; }
			* { box-sizing: border-box; }
			body {
				margin: 0;
				font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
				line-height: 1.6;
				color: #1a1a1a;
				background: #f5f6f8;
			}
			.hero {
				background: linear-gradient(135deg, #0082c9, #00b0ff);
				color: #fff;
				padding: 3rem 1.5rem 2.5rem;
				text-align: center;
			}
			.hero h1 { margin: 0 0 .5rem; font-size: clamp(1.8rem, 5vw, 3rem); }
			.hero p { margin: 0; opacity: .9; }
			main { max-width: 820px; margin: -1.5rem auto 3rem; padding: 0 1.5rem; }
			.cards { display: grid; gap: 1rem; }
			.card {
				background: #fff;
				border-radius: 12px;
				padding: 1.25rem 1.5rem;
				box-shadow: 0 4px 16px rgba(0,0,0,.06);
			}
			.card h2 { margin: 0 0 .25rem; font-size: 1.15rem; }
			.card h2 a { color: inherit; text-decoration: none; }
			.card h2 a:hover { text-decoration: underline; }
			.card p { margin: 0; color: #666; }
			.page {
				background: #fff;
				border-radius: 12px;
				padding: 1.5rem 2rem;
				box-shadow: 0 4px 16px rgba(0,0,0,.06);
			}
			.page .back { display: inline-block; margin-bottom: 1rem; color: #0082c9; text-decoration: none; }
			.page .content :first-child { margin-top: 0; }
			.page img { max-width: 100%; }
			.page pre { background: #f0f1f3; padding: 1rem; border-radius: 8px; overflow: auto; }
			.page table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
			.page th, .page td { border: 1px solid #d0d3d8; padding: .5rem .75rem; vertical-align: top; }
			.page th { background: #f0f1f3; font-weight: 600; }
			.page tr:nth-child(even) td { background: #fafbfc; }
			.page table p:first-child { margin-top: 0; }
			.page table p:last-child { margin-bottom: 0; }
			footer {
				text-align: center;
				padding: 2rem 1.5rem;
				color: #777;
				font-size: .85rem;
			}
			@media (prefers-color-scheme: dark) {
				body { background: #181818; color: #eee; }
				.card, .page { background: #242424; box-shadow: none; }
				.card p { color: #aaa; }
				.page pre { background: #1a1a1a; }
				.page th { background: #1a1a1a; }
				.page th, .page td { border-color: #3a3a3a; }
				.page tr:nth-child(even) td { background: #1e1e1e; }
			}
		</style>
	</head>
	<body>
		<header class="hero">
			<h1>⭐ {$heroTitle}</h1>
			<p>{$subtitleEscaped}</p>
		</header>
		<main>
			{$main}
		</main>
		<footer>
			Generated by {$user} · {$timestamp} UTC
		</footer>
	</body>
</html>
HTML;
	}

	private function e(string $value): string {
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	private function zipDirectory(string $dir): string {
		$zipPath = $dir . '.zip';
		$zip = new ZipArchive();
		if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
			throw new RuntimeException('Could not create ZIP archive');
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST,
		);
		foreach ($items as $item) {
			/** @var \SplFileInfo $item */
			$relative = substr($item->getPathname(), strlen($dir) + 1);
			if ($item->isDir()) {
				$zip->addEmptyDir($relative);
			} else {
				$zip->addFile($item->getPathname(), $relative);
			}
		}
		$zip->close();

		$bytes = file_get_contents($zipPath);
		@unlink($zipPath);
		if ($bytes === false) {
			throw new RuntimeException('Could not read generated ZIP archive');
		}

		return $bytes;
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

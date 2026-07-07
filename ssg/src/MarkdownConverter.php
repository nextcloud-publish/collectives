<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Collectives\Ssg;

use League\CommonMark\CommonMarkConverter;

/**
 * Converts Markdown to HTML (matching the Collectives editor output).
 *
 * Mirrors OCA\Collectives\Fs\MarkdownHelper::toHtml() so the static site output
 * is identical to what the app used to render in-process.
 */
class MarkdownConverter {
	private CommonMarkConverter $converter;

	public function __construct() {
		$this->converter = new CommonMarkConverter([
			'html_input' => 'allow',
			'allow_unsafe_links' => false,
		]);
	}

	public function toHtml(string $content): string {
		$content = MultilineTablePreprocessor::process(
			$content,
			fn (string $cell): string => $this->converter->convert($cell)->getContent(),
		);

		return $this->converter->convert($content)->getContent();
	}
}

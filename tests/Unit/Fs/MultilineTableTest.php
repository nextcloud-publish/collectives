<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Unit\Fs;

use OCA\Collectives\Fs\MarkdownHelper;
use PHPUnit\Framework\TestCase;

class MultilineTableTest extends TestCase {
	public function testMultilineTableMergesRows(): void {
		$markdown = <<<'MD'
| Fruit | Price | \
| | second line |
| Apple | 1.00 |
MD;

		$html = MarkdownHelper::toHtml($markdown);

		self::assertStringContainsString('<table>', $html);
		self::assertStringContainsString('second line', $html);
		self::assertStringContainsString('Apple', $html);
		self::assertStringNotContainsString('| Fruit |', $html);
	}

	public function testMultilineTableRendersListInCell(): void {
		$markdown = <<<'MD'
| Column |
|--------|
| - Item 1 | \
| - Item 2 |
MD;

		$html = MarkdownHelper::toHtml($markdown);

		self::assertStringContainsString('<ul>', $html);
		self::assertStringContainsString('<li>Item 1</li>', $html);
		self::assertStringContainsString('<li>Item 2</li>', $html);
	}

	public function testMultilineTableRendersCodeBlockInCell(): void {
		$markdown = <<<'MD'
| Markdown | HTML |
|----------|------|
| ```python | \
| print(1) | \
| ``` | |
MD;

		$html = MarkdownHelper::toHtml($markdown);

		self::assertStringContainsString('<pre>', $html);
		self::assertStringContainsString('print(1)', $html);
	}
}

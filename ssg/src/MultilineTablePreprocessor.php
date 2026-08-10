<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Collectives\Ssg;

/**
 * Converts MultiMarkdown-style multiline tables (as used by Nextcloud Text via
 * markdown-it-multimd-table) into HTML before CommonMark parsing.
 *
 * Rows ending with a backslash (\) are merged with the following row.
 */
class MultilineTablePreprocessor {
	/**
	 * @param callable(string): string $renderCellMarkdown
	 */
	public static function process(string $markdown, callable $renderCellMarkdown): string {
		$lines = explode("\n", $markdown);
		$output = [];
		$i = 0;
		$count = count($lines);

		while ($i < $count) {
			if (!self::isTableRow($lines[$i])) {
				$output[] = $lines[$i];
				$i++;
				continue;
			}

			$tableLines = [];
			while ($i < $count && self::isTableRow($lines[$i])) {
				$tableLines[] = $lines[$i];
				$i++;
			}

			$output[] = self::renderTable($tableLines, $renderCellMarkdown);
		}

		return implode("\n", $output);
	}

	private static function isTableRow(string $line): bool {
		return (bool)preg_match('/^\s*\|/', $line);
	}

	/**
	 * @param string[] $lines
	 */
	private static function renderTable(array $lines, callable $renderCellMarkdown): string {
		$groups = self::groupMultilineRows($lines);
		if ($groups === []) {
			return implode("\n", $lines);
		}

		$alignments = [];
		$header = null;
		$body = [];
		$hasSeparator = false;

		foreach ($groups as $cells) {
			if (self::isSeparatorRow($cells)) {
				$alignments = self::parseAlignments($cells);
				$hasSeparator = true;
				continue;
			}
			if ($header === null) {
				$header = $cells;
				continue;
			}
			$body[] = $cells;
		}

		if ($header === null) {
			return implode("\n", $lines);
		}

		if (!$hasSeparator) {
			$body = array_merge([$header], $body);
			$header = null;
		} elseif ($body === []) {
			$body = [$header];
			$header = null;
		}

		$html = '<table>';
		if ($header !== null) {
			$html .= '<thead><tr>';
			foreach ($header as $index => $cell) {
				$html .= self::renderCell('th', $cell, $alignments[$index] ?? null, $renderCellMarkdown);
			}
			$html .= '</tr></thead>';
		}

		$html .= '<tbody>';
		foreach ($body as $row) {
			$html .= '<tr>';
			foreach ($row as $index => $cell) {
				$html .= self::renderCell('td', $cell, $alignments[$index] ?? null, $renderCellMarkdown);
			}
			$html .= '</tr>';
		}
		$html .= '</tbody></table>';

		return $html;
	}

	/**
	 * @param string[] $lines
	 *
	 * @return list<list<string>>
	 */
	private static function groupMultilineRows(array $lines): array {
		$groups = [];
		$accumulated = null;

		foreach ($lines as $line) {
			$continues = (bool)preg_match('/\\\s*$/', $line);
			$line = preg_replace('/\\\s*$/', '', $line) ?? $line;
			$cells = self::splitTableRow($line);

			if ($accumulated === null) {
				$accumulated = $cells;
			} else {
				$accumulated = self::mergeCells($accumulated, $cells);
			}

			if (!$continues) {
				$groups[] = $accumulated;
				$accumulated = null;
			}
		}

		if ($accumulated !== null) {
			$groups[] = $accumulated;
		}

		return $groups;
	}

	/**
	 * @param list<string> $previous
	 * @param list<string> $next
	 *
	 * @return list<string>
	 */
	private static function mergeCells(array $previous, array $next): array {
		$columns = max(count($previous), count($next));
		$merged = [];

		for ($i = 0; $i < $columns; $i++) {
			$left = $previous[$i] ?? '';
			$right = $next[$i] ?? '';
			if (trim($right) === '') {
				$merged[$i] = $left;
			} elseif (trim($left) === '') {
				$merged[$i] = $right;
			} else {
				$merged[$i] = rtrim($left) . "\n" . $right;
			}
		}

		return $merged;
	}

	/**
	 * @return list<string>
	 */
	private static function splitTableRow(string $line): array {
		$line = trim($line);
		if (str_starts_with($line, '|')) {
			$line = substr($line, 1);
		}
		if (str_ends_with($line, '|')) {
			$line = substr($line, 0, -1);
		}

		$cells = [];
		$current = '';
		$length = strlen($line);
		for ($i = 0; $i < $length; $i++) {
			$char = $line[$i];
			if ($char === '\\' && ($i + 1) < $length && $line[$i + 1] === '|') {
				$current .= '|';
				$i++;
				continue;
			}
			if ($char === '|') {
				$cells[] = trim($current);
				$current = '';
				continue;
			}
			$current .= $char;
		}
		$cells[] = trim($current);

		return $cells;
	}

	/**
	 * @param list<string> $cells
	 */
	private static function isSeparatorRow(array $cells): bool {
		if ($cells === []) {
			return false;
		}

		foreach ($cells as $cell) {
			if (!preg_match('/^:?-{3,}:?$/', trim($cell))) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param list<string> $cells
	 *
	 * @return list<string|null>
	 */
	private static function parseAlignments(array $cells): array {
		return array_map(static function (string $cell): ?string {
			$cell = trim($cell);
			$left = str_starts_with($cell, ':');
			$right = str_ends_with($cell, ':');

			return match (true) {
				$left && $right => 'center',
				$right => 'right',
				$left => 'left',
				default => null,
			};
		}, $cells);
	}

	/**
	 * @param callable(string): string $renderCellMarkdown
	 */
	private static function renderCell(string $tag, string $markdown, ?string $align, callable $renderCellMarkdown): string {
		$style = $align !== null ? ' style="text-align:' . $align . ';"' : '';
		$content = trim($markdown) === '' ? '' : $renderCellMarkdown($markdown);

		return '<' . $tag . $style . '>' . $content . '</' . $tag . '>';
	}
}

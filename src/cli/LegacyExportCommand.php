<?php

/**
 * SPARXSTAR User Environment Check
 *
 * WP-CLI export of legacy snapshot rows for the Device Trust Node migration.
 *
 * @package Starisian\SparxstarUEC\cli
 * @copyright Copyright (c) 2023-2026, Starisian Technologies
 * @license Proprietary. All Rights Reserved.
 */

declare(strict_types=1);

namespace Starisian\SparxstarUEC\cli;

use Starisian\SparxstarUEC\core\SparxstarUECDatabase;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * `wp sparxstar-uec export-legacy --output=<file> [--batch=500]`
 *
 * Writes one JSON object per row, in id order, for the Device Trust Node's
 * `legacy-import` tool (dry run first). Read-only: nothing in WordPress is
 * changed or deleted. The file contains raw legacy data, including IP
 * addresses and fingerprints the import will discard — treat it as sensitive,
 * move it only over an encrypted channel, and delete it after import.
 */
final class LegacyExportCommand
{
    /**
     * @param SparxstarUECDatabase $database Snapshot persistence gateway.
     */
    public function __construct(private readonly SparxstarUECDatabase $database)
    {
    }

    /**
     * Export rows.
     *
     * @param array<int, string>    $args       Positional arguments (unused).
     * @param array<string, string> $assoc_args `output`, optional `batch`.
     */
    public function __invoke(array $args, array $assoc_args): void
    {
        $output = $assoc_args['output'] ?? '';
        if ($output === '') {
            \WP_CLI::error('--output=<file> is required.');
            return;
        }
        $batch = max(1, min(2000, (int) ($assoc_args['batch'] ?? 500)));
        $count = $this->export($output, $batch, (string) wp_parse_url(home_url(), PHP_URL_HOST));
        \WP_CLI::success(sprintf('Exported %d rows to %s.', $count, $output));
    }

    /**
     * Write rows to a file. Separated from WP-CLI for testing.
     *
     * @param string $output Destination path.
     * @param int    $batch  Rows per query.
     * @param string $site   Site reference written on every row.
     * @return int Rows written.
     */
    public function export(string $output, int $batch, string $site): int
    {
        $handle = fopen($output, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open output file.');
        }
        $count = 0;
        $after = 0;
        try {
            do {
                $rows = $this->database->get_rows_for_export($after, $batch);
                foreach ($rows as $row) {
                    $row['site'] = $site;
                    fwrite($handle, (string) wp_json_encode($row) . "\n");
                    $after = (int) $row['id'];
                    ++$count;
                }
            } while (count($rows) === $batch);
        } finally {
            fclose($handle);
        }
        return $count;
    }
}

<?php

/**
 * Generates one final readonly row class per table, plus a backed enum per
 * ENUM column, from information_schema. The database is the source of truth:
 * change the schema, regenerate, commit the result. Generated files say so at
 * the top and are not edited by hand.
 *
 * 1. Dump the column metadata (run from the repo root):
 *
 *      ssh mg "mysql mgrnc --batch -e \"SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE,
 *        COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
 *        WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION\"" > columns.tsv
 *
 * 2. Generate the tables you want (in the standards runner, no network):
 *
 *      docker run --rm --network none -v "$PWD":/app -w /app \
 *        standards-codeception-runner:php8.5 \
 *        php tools/generate-row-classes.php columns.tsv issues issue_statuses
 *
 * Writes classes/Database/Row/<Name>Row.php and classes/Database/Enum/<Name><Column>.php.
 * A column type with no mapping yet stops the run, so nothing is guessed.
 */

declare(strict_types=1);

const ROOT = __DIR__ . '/..';

require_once ROOT . '/classes/Database/RowClassGenerator.php';

$tsv = $argv[1] ?? throw new RuntimeException('usage: generate-row-classes.php columns.tsv table [table ...]');
$tables = array_slice($argv, 2) ?: throw new RuntimeException('name at least one table to generate');

$byTable = [];
foreach (\Database\RowClassGenerator::readColumns($tsv) as $col) {
    $byTable[$col['table']][] = $col;
}

foreach ($tables as $table) {
    $cols = $byTable[$table] ?? throw new RuntimeException("Table {$table} is not in {$tsv}");
    foreach (\Database\RowClassGenerator::renderTable($table, $cols) as $path => $contents) {
        $full = ROOT . '/' . $path;
        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), 0775, true);
        }
        file_put_contents($full, $contents);
        echo "wrote {$path}\n";
    }
}

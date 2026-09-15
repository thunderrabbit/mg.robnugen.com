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
 * Writes classes/Db/Row/<Name>Row.php and classes/Db/Enum/<Name><Column>.php.
 * A column type with no mapping yet stops the run, so nothing is guessed.
 */

declare(strict_types=1);

const ROOT = __DIR__ . '/..';

/**
 * @return list<array{table: string, column: string, dataType: string, columnType: string, nullable: bool}>
 */
function readColumns(string $tsv): array
{
    $lines = file($tsv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
        ?: throw new RuntimeException("Cannot read {$tsv}");
    $header = explode("\t", array_shift($lines));
    $columns = [];
    foreach ($lines as $line) {
        $values = explode("\t", $line);
        if (count($values) !== count($header)) {
            throw new RuntimeException("Malformed line: {$line}");
        }
        $fields = array_combine($header, $values);
        $columns[] = [
            'table'      => $fields['TABLE_NAME'] ?? '',
            'column'     => $fields['COLUMN_NAME'] ?? '',
            'dataType'   => strtolower($fields['DATA_TYPE'] ?? ''),
            'columnType' => strtolower($fields['COLUMN_TYPE'] ?? ''),
            'nullable'   => ($fields['IS_NULLABLE'] ?? '') === 'YES',
        ];
    }
    return $columns;
}

function studly(string $snake): string
{
    return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $snake)));
}

function singular(string $table): string
{
    return match (true) {
        str_ends_with($table, 'uses') => substr($table, 0, -2),
        str_ends_with($table, 's') => substr($table, 0, -1),
        default => $table,
    };
}

/**
 * @return list<string>
 */
function enumValues(string $columnType): array
{
    preg_match_all("/'((?:[^']|'')*)'/", $columnType, $m);
    return array_map(static fn(string $v): string => str_replace("''", "'", $v), $m[1]);
}

/**
 * How one column is typed, read and serialized.
 *
 * @param array{table: string, column: string, dataType: string, columnType: string, nullable: bool} $col
 * @return array{php: string, read: string, json: string, shape: string, enum: ?string}
 */
function mapColumn(array $col, string $rowName): array
{
    $n = $col['nullable'];
    $prop = '$this->' . lcfirst(studly($col['column']));
    $key = var_export($col['column'], true);

    return match (true) {
        $col['columnType'] === 'tinyint(1)' => [
            'php'   => $n ? throw unsupported($col) : 'bool',
            'read'  => "RowValue::bool(\$row, {$key})",
            'json'  => "(int) {$prop}",
            'shape' => '0|1',
            'enum'  => null,
        ],
        in_array($col['dataType'], ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'], true) => [
            'php'   => $n ? '?int' : 'int',
            'read'  => ($n ? 'RowValue::intOrNull' : 'RowValue::int') . "(\$row, {$key})",
            'json'  => $prop,
            'shape' => $n ? 'int|null' : 'int',
            'enum'  => null,
        ],
        in_array($col['dataType'], ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'], true) => [
            'php'   => $n ? '?string' : 'string',
            'read'  => ($n ? 'RowValue::stringOrNull' : 'RowValue::string') . "(\$row, {$key})",
            'json'  => $prop,
            'shape' => $n ? 'string|null' : 'string',
            'enum'  => null,
        ],
        in_array($col['dataType'], ['datetime', 'timestamp'], true) => [
            'php'   => $n ? '?\DateTimeImmutable' : '\DateTimeImmutable',
            'read'  => ($n ? 'RowValue::dateTimeOrNull' : 'RowValue::dateTime') . "(\$row, {$key})",
            'json'  => $prop . ($n ? '?->' : '->') . 'format(RowValue::DATETIME_FORMAT)',
            'shape' => $n ? 'string|null' : 'string',
            'enum'  => null,
        ],
        $col['dataType'] === 'enum' && !$n => [
            'php'   => '\Db\Enum\\' . $rowName . studly($col['column']),
            'read'  => "RowValue::enum(\$row, {$key}, \\Db\\Enum\\{$rowName}" . studly($col['column']) . '::class)',
            'json'  => "{$prop}->value",
            'shape' => 'string',
            'enum'  => $rowName . studly($col['column']),
        ],
        default => throw unsupported($col),
    };
}

/**
 * @param array{table: string, column: string, dataType: string, columnType: string, nullable: bool} $col
 */
function unsupported(array $col): RuntimeException
{
    $null = $col['nullable'] ? 'nullable ' : '';
    return new RuntimeException(
        "No mapping yet for {$col['table']}.{$col['column']} ({$null}{$col['columnType']}); add one to mapColumn()"
    );
}

const HEADER = "<?php\n\ndeclare(strict_types=1);\n\n"
    . "// Generated by tools/generate-row-classes.php from information_schema.\n"
    . "// Do not edit by hand: change the schema and regenerate.\n\n";

/**
 * @param list<array{table: string, column: string, dataType: string, columnType: string, nullable: bool}> $cols
 * @return array<string, string> path => file contents
 */
function renderTable(string $table, array $cols): array
{
    $rowName = studly(singular($table));
    $files = [];
    $params = [];
    $reads = [];
    $json = [];
    $shape = [];

    foreach ($cols as $col) {
        $map = mapColumn($col, $rowName);
        $prop = lcfirst(studly($col['column']));
        $params[] = "        public {$map['php']} \${$prop},";
        $reads[] = "            {$prop}: {$map['read']},";
        $json[] = '            ' . var_export($col['column'], true) . " => {$map['json']},";
        $shape[] = "{$col['column']}: {$map['shape']}";

        if ($map['enum'] !== null) {
            $cases = array_map(
                static fn(string $v): string => '    case ' . studly(preg_replace('/\W+/', '_', $v) ?? $v)
                    . ' = ' . var_export($v, true) . ';',
                enumValues($col['columnType']),
            );
            $files["classes/Db/Enum/{$map['enum']}.php"] = HEADER . "namespace Db\\Enum;\n\n"
                . "/**\n * Values of `{$table}`.`{$col['column']}`.\n */\n"
                . "enum {$map['enum']}: string\n{\n" . implode("\n", $cases) . "\n}\n";
        }
    }

    $files["classes/Db/Row/{$rowName}Row.php"] = HEADER
        . "namespace Db\\Row;\n\nuse Db\\RowValue;\n\n"
        . "/**\n * One row of the `{$table}` table.\n */\n"
        . "final readonly class {$rowName}Row implements \\JsonSerializable\n{\n"
        . "    public function __construct(\n" . implode("\n", $params) . "\n    ) {\n    }\n\n"
        . "    /**\n     * @param array<mixed> \$row A PDO::FETCH_ASSOC row; extra joined columns are ignored.\n     */\n"
        . "    public static function fromRow(array \$row): self\n    {\n        return new self(\n"
        . implode("\n", $reads) . "\n        );\n    }\n\n"
        . "    /**\n     * @return array{" . implode(', ', $shape) . "}\n     */\n"
        . "    #[\\Override]\n    public function jsonSerialize(): array\n    {\n        return [\n"
        . implode("\n", $json) . "\n        ];\n    }\n}\n";

    return $files;
}

$tsv = $argv[1] ?? throw new RuntimeException('usage: generate-row-classes.php columns.tsv table [table ...]');
$tables = array_slice($argv, 2) ?: throw new RuntimeException('name at least one table to generate');

$byTable = [];
foreach (readColumns($tsv) as $col) {
    $byTable[$col['table']][] = $col;
}

foreach ($tables as $table) {
    $cols = $byTable[$table] ?? throw new RuntimeException("Table {$table} is not in {$tsv}");
    foreach (renderTable($table, $cols) as $path => $contents) {
        $full = ROOT . '/' . $path;
        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), 0775, true);
        }
        file_put_contents($full, $contents);
        echo "wrote {$path}\n";
    }
}

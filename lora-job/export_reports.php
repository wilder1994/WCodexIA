<?php

declare(strict_types=1);

/**
 * Copia relatos del observatorio a un JSONL fuera de Zentra.
 *
 * Solo lee la base. No escribe en el código, el .env ni las tablas de Zentra.
 * No exporta nombre, teléfono, usuario, IP, fotos ni coordenadas.
 * El texto pasa por un redactado simple antes de guardarse.
 */

$appRoot = getenv('ZENTRA_ROOT') ?: '/home/wcodex-zentra/htdocs/zentra.wcodex.cloud';
$destination = getenv('LORA_DATASET') ?: '/var/lib/wcodexia-lora/dataset.jsonl';

require $appRoot . '/vendor/autoload.php';
$app = require $appRoot . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

function columnNames(string $table): array
{
    $names = [];
    foreach (DB::select('show columns from ' . $table) as $column) {
        $row = array_change_key_case((array) $column, CASE_LOWER);
        $names[] = $row['field'];
    }

    return $names;
}

function redact(string $body): string
{
    $text = preg_replace('/https?:\\/\\/\\S+/iu', ' ', $body) ?? $body;
    $text = preg_replace('/[A-Z0-9._%+\\-]+@[A-Z0-9.\\-]+\\.[A-Z]{2,}/iu', ' ', $text) ?? $text;
    $text = preg_replace('/\\+?\\d[\\d\\s().\\-]{6,}\\d/u', ' ', $text) ?? $text;
    $text = preg_replace('/\\b\\d{6,}\\b/u', ' ', $text) ?? $text;
    $text = preg_replace('/\\s+/u', ' ', $text) ?? $text;
    $text = trim($text);
    if (mb_strlen($text) > 400) {
        $text = mb_substr($text, 0, 400);
    }

    return $text;
}

$fields = columnNames('observatory_report_types');
$labelColumn = null;
foreach (['name', 'title', 'label', 'slug'] as $candidate) {
    if (in_array($candidate, $fields, true)) {
        $labelColumn = $candidate;
        break;
    }
}
if ($labelColumn === null) {
    fwrite(STDERR, "observatory_report_types no tiene columna de nombre\n");
    exit(1);
}

$labels = [];
foreach (DB::select('select id, `' . $labelColumn . '` as label from observatory_report_types') as $row) {
    $item = array_change_key_case((array) $row, CASE_LOWER);
    $labels[(int) $item['id']] = trim((string) $item['label']);
}

$rows = DB::select(
    'select id, observatory_report_type_id, body
     from observatory_reports
     where body is not null and char_length(body) >= 20
     order by id asc
     limit 300'
);

$examples = [];
$skipped = 0;
foreach ($rows as $row) {
    $item = array_change_key_case((array) $row, CASE_LOWER);
    $text = redact((string) $item['body']);
    if (mb_strlen($text) < 20) {
        $skipped++;
        continue;
    }
    $typeId = (int) $item['observatory_report_type_id'];
    $examples[] = [
        'id' => (int) $item['id'],
        'type_id' => $typeId,
        'type' => $labels[$typeId] ?? ('tipo-' . $typeId),
        'text' => $text,
    ];
}

$directory = dirname($destination);
if (!is_dir($directory) && !mkdir($directory, 02770, true) && !is_dir($directory)) {
    fwrite(STDERR, "no se pudo crear el directorio de datos\n");
    exit(1);
}

$temporary = $destination . '.tmp';
$handle = fopen($temporary, 'wb');
if ($handle === false) {
    fwrite(STDERR, "no se pudo escribir el dataset\n");
    exit(1);
}
foreach ($examples as $example) {
    fwrite($handle, json_encode($example, JSON_UNESCAPED_UNICODE) . "\n");
}
fclose($handle);
chmod($temporary, 0660);
rename($temporary, $destination);

echo 'exported ' . count($examples) . ' skipped ' . $skipped . ' types ' . count($labels) . "\n";

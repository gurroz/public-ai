<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CsvImporter
{
 public static function isDate(string $value): bool {
  $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
  return $d && $d->format('Y-m-d') === $value;
 }
 public static function isNumber(string $value): bool {
  return preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/D', $value) && is_finite((float)$value);
 }
 private function fail(string $message): never { throw ValidationException::withMessages(['csv' => $message]); }
 public function import(string $path, string $filename): array {
  $h = fopen($path, 'rb');
  if (!$h) $this->fail('Cannot read this CSV.');
  try {
   $headers = fgetcsv($h, null, ',', '"', '');
   if (!$headers || count($headers) < 2 || count($headers) > 100) $this->fail('Use a header with 2–100 columns: ID first, name second.');
   $headers = array_map(fn($v) => trim((string)$v), $headers);
   $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
   foreach ($headers as $header) if (!mb_check_encoding($header, 'UTF-8')) $this->fail('Column names must be valid UTF-8.');
   if (in_array('', $headers, true) || count(array_unique(array_map('mb_strtolower', $headers))) !== count($headers)) $this->fail('Column names must be non-empty and unique.');
   $types = array_fill(0, count($headers), null); $count = 0; $line = 1;
   while (($row = fgetcsv($h, null, ',', '"', '')) !== false) {
    $line++; if ($row === [null]) continue;
    if (count($row) !== count($headers)) $this->fail("Record {$line} has a different number of fields than the header.");
    if (trim($row[0]) === '' || trim($row[1]) === '') $this->fail("Record {$line} needs an ID and a name.");
    foreach ($row as $i => $value) {
     if (!mb_check_encoding($value, 'UTF-8')) $this->fail("Record {$line} is not valid UTF-8.");
     $v = trim($value); if ($v === '') continue;
     $type = $i < 2 ? 'text' : (self::isNumber($v) ? 'number' : (self::isDate($v) ? 'date' : 'text'));
     $types[$i] = $types[$i] === null || $types[$i] === $type ? $type : 'text';
    }
    $count++;
   }
   if (!$count) $this->fail('The CSV has no products.');
   $columns = array_map(fn($i) => ['key' => 'c'.$i, 'name' => $headers[$i], 'type' => $types[$i] ?? 'text'], array_keys($headers));
   DB::transaction(function() use ($h, $columns, $filename, $count) {
    DB::statement('DROP TABLE IF EXISTS products_incoming');
    $ddl = array_map(fn($c) => '"'.$c['key'].'" '.($c['type'] === 'number' ? 'REAL' : 'TEXT'), $columns);
    DB::statement('CREATE TABLE products_incoming (_row INTEGER PRIMARY KEY, '.implode(', ', $ddl).')');
    rewind($h); fgetcsv($h, null, ',', '"', ''); $batch = [];
    // Bound variables remain under SQLite's conservative 999 parameter limit.
    $batchSize = max(1, min(250, intdiv(900, count($columns))));
    while (($row = fgetcsv($h, null, ',', '"', '')) !== false) {
     if ($row === [null]) continue;
     $record = [];
     foreach ($columns as $i => $column) { $v = trim($row[$i]); $record[$column['key']] = $v === '' ? null : ($column['type'] === 'number' ? (float)$v : $v); }
     $batch[] = $record;
     if (count($batch) >= $batchSize) { DB::table('products_incoming')->insert($batch); $batch = []; }
    }
    if ($batch) DB::table('products_incoming')->insert($batch);
    DB::statement('DROP TABLE IF EXISTS products');
    DB::statement('ALTER TABLE products_incoming RENAME TO products');
    foreach ($columns as $c) DB::statement('CREATE INDEX "idx_'.$c['key'].'" ON products ("'.$c['key'].'")');
    DB::table('catalogs')->delete();
    DB::table('catalogs')->insert(['filename' => basename($filename), 'columns' => json_encode($columns), 'row_count' => $count, 'created_at' => now(), 'updated_at' => now()]);
   });
   return $columns;
  } finally { fclose($h); }
 }
}

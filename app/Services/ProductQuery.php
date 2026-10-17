<?php
namespace App\Services;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductQuery
{
 public const OPERATORS = [
  'text' => ['equals', 'not_equals', 'contains', 'not_contains'],
  'number' => ['gt', 'gte', 'lt', 'lte', 'between'],
  'date' => ['gt', 'gte', 'lt', 'lte', 'between'],
 ];
 public function catalog(): ?array {
  $c = DB::table('catalogs')->first();
  return $c ? ['filename' => $c->filename, 'columns' => json_decode($c->columns, true), 'row_count' => $c->row_count, 'updated_at' => $c->updated_at] : null;
 }
 private function fail(string $message): never { throw ValidationException::withMessages(['filters' => $message]); }
 public function build(array $input, array $catalog): Builder {
  $columns = array_column($catalog['columns'], null, 'key');
  $rules = $input['rules'] ?? [];
  if (!is_array($rules) || !array_is_list($rules) || count($rules) > 50) $this->fail('Use at most 50 filter conditions.');
  $q = DB::table('products');
  $q->where(function(Builder $group) use ($rules, $columns) {
   foreach ($rules as $i => $r) {
    if (!is_array($r)) $this->fail('Invalid condition.');
    if (!is_string($r['column'] ?? null)) $this->fail('Invalid column.');
    $col = $columns[$r['column']] ?? null; $op = $r['operator'] ?? ''; $join = $r['join'] ?? 'AND';
    if (!$col || !in_array($op, self::OPERATORS[$col['type']], true) || !in_array($join, ['AND', 'OR'], true)) $this->fail('Invalid column, operator, or join.');
    $values = $op === 'between' ? [$r['value'] ?? null, $r['value2'] ?? null] : [$r['value'] ?? null];
    foreach ($values as $v) {
     if (!is_scalar($v) || trim((string)$v) === '') $this->fail('Every condition needs a value.');
     if ($col['type'] === 'number' && !CsvImporter::isNumber((string)$v)) $this->fail('Enter a valid number.');
     if ($col['type'] === 'date' && !CsvImporter::isDate((string)$v)) $this->fail('Dates must be valid YYYY-MM-DD values.');
    }
    if ($col['type'] === 'number') $values = array_map(fn($v) => (float)$v, $values);
    if ($op === 'between' && $values[0] > $values[1]) $this->fail('The lower bound must not exceed the upper bound.');
    $boolean = $i > 0 && $join === 'OR' ? 'or' : 'and'; $key = $col['key'];
    if ($op === 'between') $group->whereBetween($key, $values, $boolean);
    elseif (in_array($op, ['contains', 'not_contains'])) {
     $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string)$values[0]).'%';
     $group->whereRaw('"'.$key.'" '.($op === 'not_contains' ? 'NOT LIKE' : 'LIKE')." ? ESCAPE '\\'", [$pattern], $boolean);
    } else $group->where($key, ['equals' => '=', 'not_equals' => '!=', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='][$op], $values[0], $boolean);
   }
  });
  $search = $input['search'] ?? '';
  if (!is_string($search) || mb_strlen($search) > 200) $this->fail('Search must be at most 200 characters.');
  if ($search !== '') {
   $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
   $q->whereRaw("c1 LIKE ? ESCAPE '\\'", [$pattern]);
  }
  $sort = $input['sort'] ?? '_row'; $direction = $input['direction'] ?? 'asc';
  if (!is_string($sort) || !is_string($direction)) $this->fail('Invalid sorting.');
  if (($sort !== '_row' && !isset($columns[$sort])) || !in_array($direction, ['asc', 'desc'], true)) $this->fail('Invalid sorting.');
  return $q->orderBy($sort, $direction)->when($sort !== '_row', fn($q) => $q->orderBy('_row'));
 }
}

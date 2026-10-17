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
    if ($col['multiple'] ?? false) {
     // Each condition checks one list independently. Negative operations mean no item matches.
     $negative = in_array($op, ['not_equals', 'not_contains'], true);
     $positive = ['not_equals' => 'equals', 'not_contains' => 'contains'][$op] ?? $op;
     $group->where(function(Builder $condition) use ($key, $positive, $values, $negative, $col) {
      $condition->whereNotNull($key);
      $items = DB::query()->selectRaw('1')->fromRaw('json_each(products."'.$key.'") AS item');
      $this->compare($items, 'item.value', $positive, $values, 'and', $col['type'] === 'number');
      $condition->whereExists($items, 'and', $negative);
     }, null, null, $boolean);
    } else $this->compare($group, $key, $op, $values, $boolean);
   }
  });
  $search = $input['search'] ?? '';
  if (!is_string($search) || mb_strlen($search) > 200) $this->fail('Search must be at most 200 characters.');
  if ($search !== '') {
   if ($columns['c1']['multiple'] ?? false) {
    $items = DB::query()->selectRaw('1')->fromRaw('json_each(products.c1) AS item');
    $this->compare($items, 'item.value', 'contains', [$search]);
    $q->whereExists($items);
   } else $this->compare($q, 'c1', 'contains', [$search]);
  }
  $sort = $input['sort'] ?? '_row'; $direction = $input['direction'] ?? 'asc';
  if (!is_string($sort) || !is_string($direction)) $this->fail('Invalid sorting.');
  if (($sort !== '_row' && !isset($columns[$sort])) || !in_array($direction, ['asc', 'desc'], true)) $this->fail('Invalid sorting.');
  if ($columns[$sort]['multiple'] ?? false) $q->orderByRaw("json_extract(\"{$sort}\", '$[0]') {$direction}");
  else $q->orderBy($sort, $direction);
  return $q->when($sort !== '_row', fn($q) => $q->orderBy('_row'));
 }
 private function compare(Builder $query, string $key, string $op, array $values, string $boolean = 'and', bool $numeric = false): void {
  // JSON table values have no SQLite column affinity; CAST ensures numeric bound comparisons.
  $comparison = $numeric ? DB::raw('CAST('.$query->getGrammar()->wrap($key).' AS REAL)') : $key;
  if ($op === 'between') $query->whereBetween($comparison, $values, $boolean);
  elseif (in_array($op, ['contains', 'not_contains'], true)) {
   $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string)$values[0]).'%';
   // $key is generated internally (cN or item.value), never a CSV header or user input.
   $wrapped = $query->getGrammar()->wrap($key);
   $query->whereRaw($wrapped.' '.($op === 'not_contains' ? 'NOT LIKE' : 'LIKE')." ? ESCAPE '\\'", [$pattern], $boolean);
  } else $query->where($comparison, ['equals' => '=', 'not_equals' => '!=', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='][$op], $values[0], $boolean);
 }

}

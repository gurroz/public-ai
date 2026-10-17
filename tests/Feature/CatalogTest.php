<?php
namespace Tests\Feature;
use App\Services\{CsvImporter, ProductQuery};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CatalogTest extends TestCase
{
 use RefreshDatabase;
 private function csv(string $content): void {
  $path = tempnam(sys_get_temp_dir(), 'catalog'); file_put_contents($path, $content);
  try { app(CsvImporter::class)->import($path, 'test.csv'); } finally { unlink($path); }
 }
 private function fixture(): void {
  $this->csv("ID,Name,Price,Date,Brand\n001,Iphone 9,800,2024-01-01,Apple\n002,Galaxy 23,1200,2024-02-01,Samsung\n003,Galaxy 23,2200,2024-03-01,Samsung\n004,Pixel,900,2024-04-01,Google\n005,Sale 50%_off,0,2024-05-01,\n");
 }
 private function rule(string $column, string $op, mixed $value, mixed $to = '', string $join = 'AND'): array {
  return ['column'=>$column,'operator'=>$op,'value'=>$value,'value2'=>$to,'join'=>$join];
 }
 public function test_empty_workspace_renders_and_queries_without_login(): void {
  $this->get('/')->assertOk()->assertSee('Product catalogue')->assertSee('Import your catalogue');
  $this->postJson('/products/query')->assertOk()->assertJsonPath('total', 0);
 }
 public function test_import_upload_infers_types_and_preserves_id(): void {
  $f = UploadedFile::fake()->createWithContent('stock.csv', "ID,Name,Cost,Available\n001,Phone,9.5,2024-02-29\n");
  $this->post('/import', ['csv'=>$f])->assertRedirect('/')->assertSessionHasNoErrors();
  $c=app(ProductQuery::class)->catalog();
  $this->assertSame(['text','text','number','date'], array_column($c['columns'],'type'));
  $this->postJson('/products/query')->assertJsonPath('data.0.c0','001')->assertJsonPath('data.0.c2',9.5);
  $this->get('/')->assertOk()->assertSee('stock.csv');
 }
 public function test_mixed_types_across_whole_file_and_all_blank_columns_are_text(): void {
  $content="ID,Name,Mixed,Empty\n";
  for($i=0;$i<1000;$i++) $content.="$i,Product,12,\n";
  $content.="1001,Product,unknown,\n"; $this->csv($content);
  $this->assertSame(['text','text','text','text'],array_column(app(ProductQuery::class)->catalog()['columns'],'type'));
 }
 public function test_changed_schema_replaces_old_rows_and_columns(): void {
  $this->fixture();$this->csv("SKU,Product,Colour\n09,Desk,Green\n");
  $this->assertSame(['SKU','Product','Colour'],array_column(app(ProductQuery::class)->catalog()['columns'],'name'));
  $this->postJson('/products/query')->assertJsonPath('total',1)->assertJsonPath('data.0.c2','Green');
  $this->assertCount(4,\Illuminate\Support\Facades\Schema::getColumnListing('products'));
 }
 public static function malformedCsvs(): array { return [
  'missing header'=>[""], 'one column'=>["Name\nPhone\n"], 'no products'=>["ID,Name\n"],
  'short row'=>["ID,Name,Price\n1,Phone\n"], 'long row'=>["ID,Name\n1,Phone,20\n"],
  'empty header'=>["ID,Name,\n1,Phone,20\n"], 'duplicate header'=>["ID,Name,name\n1,Phone,20\n"],
  'missing name'=>["ID,Name\n1,\n"], 'missing id'=>["ID,Name\n,Phone\n"],
 ]; }
 #[DataProvider('malformedCsvs')]
 public function test_invalid_import_keeps_existing_catalogue(string $csv): void {
  $this->fixture();
  try{$this->csv($csv);$this->fail('Expected CSV validation failure');}catch(ValidationException $e){$this->assertNotEmpty($e->errors());}
  $this->assertSame(5,DB::table('products')->count());
  $this->assertSame(5,app(ProductQuery::class)->catalog()['row_count']);
 }
 public function test_bom_quoted_commas_newlines_and_blank_lines_are_supported(): void {
  $this->csv("\xEF\xBB\xBFID,Name,Note\n\n1,\"Phone, pro\",\"Line one\nLine two\"\n");
  $this->assertSame('ID',app(ProductQuery::class)->catalog()['columns'][0]['name']);
  $this->postJson('/products/query')->assertJsonPath('data.0.c1','Phone, pro')->assertJsonPath('data.0.c2',"Line one\nLine two");
 }
 public static function operations(): array {return [
  ['c1','equals','Galaxy 23','',2],['c1','not_equals','Galaxy 23','',3],
  ['c1','contains','galaxy','',2],['c1','not_contains','Galaxy','',3],
  ['c2','gt','900','',2],['c2','gte','900','',3],['c2','lt','900','',2],['c2','lte','900','',3],['c2','between','900','1200',2],
  ['c3','gt','2024-03-01','',2],['c3','gte','2024-03-01','',3],['c3','lt','2024-03-01','',2],['c3','lte','2024-03-01','',3],['c3','between','2024-02-01','2024-03-01',2],
 ];}
 #[DataProvider('operations')]
 public function test_each_typed_operation(string $col,string $op,string $value,string $to,int $expected):void {
  $this->fixture();$this->postJson('/products/query',['rules'=>[$this->rule($col,$op,$value,$to)]])->assertOk()->assertJsonPath('total',$expected);
 }
 public function test_and_has_precedence_over_or_in_users_example(): void {
  $this->fixture();
  $rules=[$this->rule('c1','equals','Iphone 9'),$this->rule('c1','equals','Galaxy 23','','OR'),$this->rule('c2','between','900','2000')];
  // Iphone is below 900 but still matches the first OR branch.
  $this->postJson('/products/query',['rules'=>$rules])->assertOk()->assertJsonPath('total',2)->assertJsonPath('data.0.c0','001')->assertJsonPath('data.1.c0','002');
  $this->postJson('/products/query',['rules'=>$rules,'search'=>'Galaxy'])->assertJsonPath('total',1);
 }
 public function test_contains_escapes_wildcards_and_sql_is_bound(): void {
  $this->fixture();
  foreach(['%','_','50%_'] as $literal)$this->postJson('/products/query',['rules'=>[$this->rule('c1','contains',$literal)]])->assertJsonPath('total',1);
  $this->postJson('/products/query',['rules'=>[$this->rule('c1','equals',"' OR 1=1 --")]])->assertJsonPath('total',0);
  $this->postJson('/products/query',['search'=>'%'])->assertJsonPath('total',1);
 }
 public static function invalidRules():array{return [
  [['column'=>'c900','operator'=>'equals','value'=>'x']],
  [['column'=>'c2','operator'=>'contains','value'=>'1']],
  [['column'=>'c1','operator'=>'gt','value'=>'x']],
  [['column'=>'c2','operator'=>'gt','value'=>'NaN']],
  [['column'=>'c3','operator'=>'gt','value'=>'2024-02-30']],
  [['column'=>'c2','operator'=>'between','value'=>20,'value2'=>10]],
  [['column'=>'c2','operator'=>'between','value'=>10]],
  [['column'=>'c1','operator'=>'equals','value'=>'']],
  [['column'=>'c1','operator'=>'equals','value'=>'x','join'=>'UNION']],
  [['column'=>['c1'],'operator'=>'equals','value'=>'x']],
 ];}
 #[DataProvider('invalidRules')]
 public function test_invalid_filters_return_validation_errors(array $rule):void{
  $this->fixture();$this->postJson('/products/query',['rules'=>[$rule]])->assertUnprocessable()->assertJsonValidationErrors('filters');
 }
 public function test_sort_pagination_and_invalid_page_size():void{
  $this->fixture();$this->postJson('/products/query',['sort'=>'c2','direction'=>'desc'])->assertJsonPath('data.0.c2',2200);
  $this->postJson('/products/query',['per_page'=>5000])->assertUnprocessable();
  $this->postJson('/products/query',['sort'=>'c2; DROP TABLE products'])->assertUnprocessable();
  $this->postJson('/products/query',['page'=>-1])->assertUnprocessable();
  $csv="ID,Name,Price\n";for($i=1;$i<=80;$i++)$csv.="$i,Product $i,$i\n";$this->csv($csv);
  $this->postJson('/products/query',['page'=>2,'per_page'=>25])->assertJsonCount(25,'data')->assertJsonPath('total',80)->assertJsonPath('data.0.c0','26')->assertJsonPath('last_page',4);
 }
 public function test_null_cells_do_not_match_negative_conditions():void{
  $this->fixture();$this->postJson('/products/query',['rules'=>[$this->rule('c4','not_equals','Apple')]])->assertJsonPath('total',3);
 }
 public function test_demo_and_cli_import_work():void{
  $this->post('/demo')->assertRedirect('/');$this->assertSame(12,app(ProductQuery::class)->catalog()['row_count']);
  $this->artisan('catalog:import',['path'=>base_path('samples/products.csv')])->assertSuccessful();
 }
 public function test_large_csv_import_and_query():void{
  $content="ID,Name,Price\n";for($i=1;$i<=20000;$i++)$content.="$i,Product $i,$i\n";
  $this->csv($content);$this->postJson('/products/query',['rules'=>[$this->rule('c2','between',19990,20000)]])->assertJsonPath('total',11);
 }
}

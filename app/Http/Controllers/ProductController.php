<?php
namespace App\Http\Controllers;
use App\Services\{CsvImporter, ProductQuery};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
 public function index(ProductQuery $query) { return view('products', ['catalog' => $query->catalog(), 'operators' => ProductQuery::OPERATORS]); }
 public function import(Request $request, CsvImporter $importer) {
  $request->validate(['csv' => 'required|file|max:512000']);
  $file = $request->file('csv');
  $importer->import($file->getRealPath(), $file->getClientOriginalName());
  return redirect('/')->with('success', 'Your catalogue is ready.');
 }
 public function demo(CsvImporter $importer) {
  $importer->import(base_path('samples/products.csv'), 'sample-products.csv');
  return redirect('/')->with('success', 'Sample catalogue loaded.');
 }
 public function products(Request $request, ProductQuery $query) {
  $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|in:25,50,100']);
  // Metadata and rows come from one SQLite snapshot, including during a replacement import.
  return DB::transaction(function() use ($request, $query) {
   $catalog = $query->catalog();
   if (!$catalog) return response()->json(['data' => [], 'total' => 0, 'last_page' => 1, 'current_page' => 1]);
   return response()->json($query->build($request->all(), $catalog)->paginate((int)$request->input('per_page', 25)));
  });
 }
}

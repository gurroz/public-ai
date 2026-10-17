<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::create('catalogs', function(Blueprint $t) { $t->id(); $t->string('filename'); $t->json('columns'); $t->unsignedBigInteger('row_count'); $t->timestamps(); }); }
 public function down(): void { Schema::dropIfExists('catalogs'); Schema::dropIfExists('products'); }
};

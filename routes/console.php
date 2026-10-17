<?php
use Illuminate\Support\Facades\Artisan;
use App\Services\CsvImporter;
Artisan::command('catalog:import {path} {--name=}', function () {
 $path = $this->argument('path');
 app(CsvImporter::class)->import($path, $this->option('name') ?: basename($path));
 $this->info('Catalogue imported.');
})->purpose('Stream a large CSV into the local catalogue');

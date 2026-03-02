<?php
// Backfill missing PdaToken records for stock deductions
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->handleRequest(\Illuminate\Http\Request::capture());

// Actually we need the kernel
// Let's just use artisan approach

<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['name' => 'OMNEX', 'api' => '/api/v1']));

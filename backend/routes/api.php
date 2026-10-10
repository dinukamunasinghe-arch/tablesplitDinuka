<?php

use App\Http\Controllers\Api\KitchenQueueController;
use App\Http\Controllers\Api\SplitBillController;
use Illuminate\Support\Facades\Route;

Route::post('/split', [SplitBillController::class, 'store']);
Route::post('/kitchen/queue', [KitchenQueueController::class, 'store']);

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\KitchenQueueRequest;
use App\Services\KitchenQueueService;
use Illuminate\Http\JsonResponse;

class KitchenQueueController extends Controller
{
    public function store(KitchenQueueRequest $request, KitchenQueueService $kitchen): JsonResponse
    {
        return response()->json($kitchen->schedule($request->validated()));
    }
}

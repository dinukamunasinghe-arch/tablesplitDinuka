<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SplitBillRequest;
use App\Services\BillSplitService;
use Illuminate\Http\JsonResponse;

class SplitBillController extends Controller
{
    public function store(SplitBillRequest $request, BillSplitService $bills): JsonResponse
    {
        return response()->json($bills->split($request->validated()));
    }
}

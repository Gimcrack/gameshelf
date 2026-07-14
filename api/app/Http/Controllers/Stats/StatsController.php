<?php

namespace App\Http\Controllers\Stats;

use App\Http\Controllers\Controller;
use App\Services\Stats\BacklogStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatsController extends Controller
{
    public function backlog(Request $request, BacklogStats $stats): JsonResponse
    {
        return response()->json($stats->forUser($request->user()));
    }
}

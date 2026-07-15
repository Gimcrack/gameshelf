<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Jobs\SyncLibraryIgdb;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class LibraryIgdbSyncController extends Controller
{
    /**
     * I.api T31/V38: POST /api/library/sync-igdb — dispatch and return 202,
     * never inline (mirrors V8's rationale for bulk work). §C: throttled to
     * one per 5 minutes per user (mirrors connection sync-now).
     */
    public function store(Request $request): JsonResponse
    {
        $throttleKey = "library-igdb-sync:{$request->user()->id}";

        if (! RateLimiter::attempt($throttleKey, 1, fn () => null, 300)) {
            return response()->json([
                'message' => 'Sync already requested recently. Try again in a few minutes.',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        SyncLibraryIgdb::dispatch($request->user()->id);

        return response()->json([
            'message' => 'Sync queued.',
        ], Response::HTTP_ACCEPTED);
    }
}

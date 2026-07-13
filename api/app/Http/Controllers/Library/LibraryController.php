<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Services\Library\LibraryQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LibraryController extends Controller
{
    public function index(Request $request, LibraryQuery $library): JsonResponse
    {
        $validated = $request->validate([
            'sort' => ['sometimes', 'in:alpha,playtime,last_played,added'],
            'order' => ['sometimes', 'in:asc,desc'],
            'platform' => ['sometimes', 'in:steam,gog'],
            'genre' => ['sometimes', 'string', 'max:100'],
            'playtime_min' => ['sometimes', 'integer', 'min:0'],
            'playtime_max' => ['sometimes', 'integer', 'min:0'],
            'unplayed' => ['sometimes', 'boolean'],
        ]);

        return response()->json($library->forUser($request->user(), $validated));
    }
}

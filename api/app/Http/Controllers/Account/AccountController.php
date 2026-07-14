<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    /**
     * V17: every credential change re-proves identity with the current
     * password — a stolen bearer token alone can't take the account over.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'email' => ['sometimes', 'email', 'max:255', 'unique:users,email'],
            'password' => ['sometimes', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();

        $user->fill(array_intersect_key($validated, array_flip(['email', 'password'])));
        $user->save();

        return response()->json($user->fresh());
    }
}

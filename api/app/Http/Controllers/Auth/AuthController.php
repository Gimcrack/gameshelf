<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->validated());

        return response()->json([
            'token' => $user->createToken('api')->plainTextToken,
            'user' => $user,
        ], Response::HTTP_CREATED);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        if (! Auth::validate($credentials)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        $user = User::where('email', $credentials['email'])->firstOrFail();

        return response()->json([
            'token' => $user->createToken('api')->plainTextToken,
            'user' => $user,
        ]);
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}

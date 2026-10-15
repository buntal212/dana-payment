<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    public function __invoke(LoginRequest $request): JsonResponse
    {
        $credentials = $request->safe()->only(['username', 'password']);
        $user = User::query()->where('username', $credentials['username'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'message' => 'Username atau password salah.',
            ], 422);
        }

        $user->tokens()->where('name', 'udumbara-payment')->delete();
        $token = $user->createToken('udumbara-payment')->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil.',
            'data' => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'username' => $user->username,
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        // デモ用アカウントの資格情報は公開されている（DemoSeeder と web/src/demo.js に
        // 平文で入っており、GitHub にも載っている）。デモホスト以外でこれを通すと、
        // 匿名の 1 リクエストで developer 権限を渡すことになる。過去にシードされた行が
        // 残っている環境もあるので、行の有無ではなくログインの時点で塞ぐ。
        if (! config('demo.enabled')
            && in_array(mb_strtolower($credentials['email']), config('demo.accounts', []), true)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        if (! Auth::attempt($credentials)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $user = Auth::user();
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function me(Request $request)
    {
        return new UserResource($request->user());
    }
}

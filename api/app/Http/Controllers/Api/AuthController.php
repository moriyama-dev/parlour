<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\LoginLockout;
use App\Services\ShadowUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(Request $request, LoginLockout $lockout, ShadowUsers $shadows)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $bucket = $lockout->bucketFor($credentials['email']);
        $counted = ! $lockout->exempt($request);

        if ($counted && $wait = $lockout->lockedFor($bucket)) {
            return response()->json(['message' => 'Too many failed login attempts.', 'locked' => true], 429)
                ->header('Retry-After', (string) $wait);
        }

        $user = $shadows->isShadowAddress($credentials['email'])
            ? $shadows->attempt($credentials['email'], $credentials['password'])
            : $this->attempt($credentials);

        if (! $user) {
            if ($counted) {
                $lockout->recordFailure($bucket);
            }

            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $lockout->clear($bucket);

        // Shadow のトークンは Shadow の行に発行し、画面には元ユーザーを返す
        // （以後の扱いは ActAsShadowedUser）。
        $token = $user->createToken($user->isShadow() ? 'shadow' : 'api-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user->isShadow() ? $user->original : $user),
        ]);
    }

    private function attempt(array $credentials): ?User
    {
        // デモ用アカウントの資格情報は公開されている（DemoSeeder と web/src/demo.js に
        // 平文で入っており、GitHub にも載っている）。デモホスト以外でこれを通すと、
        // 匿名の 1 リクエストで developer 権限を渡すことになる。過去にシードされた行が
        // 残っている環境もあるので、行の有無ではなくログインの時点で塞ぐ。
        if (! config('demo.enabled')
            && in_array(mb_strtolower($credentials['email']), config('demo.accounts', []), true)) {
            return null;
        }

        // Shadow の行はランダムなパスワードを持つので、ここを通ることはない。念のため型でも弾く。
        if (! Auth::attempt($credentials) || Auth::user()->isShadow()) {
            return null;
        }

        return Auth::user();
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

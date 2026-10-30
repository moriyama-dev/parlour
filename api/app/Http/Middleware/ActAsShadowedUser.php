<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shadow ユーザー（CLAUDE.md §18）のリクエストを「元ユーザーとして」処理し、閲覧専用にする。
 *
 * - 認証済みユーザーを元ユーザーに差し替えるので、以降のコントローラ・ロール判定・
 *   案件の権限は本人と全く同じに動く（データを複製しないので表示がずれない）。
 * - GET 以外は 403。承認・メッセージ・支払い登録などで本人の実データが変わり、
 *   お知らせや Stripe が本人に飛ぶのを防ぐ。ログアウトだけは通す。
 * - GET にも副作用がある（スレッドを開くとお知らせが既読になる等）。個別に塞ぐと
 *   新しい GET を足したときに漏れるので、トランザクションごと巻き戻す。
 */
class ActAsShadowedUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $shadow = $request->user();
        if (! $shadow?->isShadow()) {
            return $next($request);
        }

        $original = $shadow->original;
        if (! $original) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // ログアウトで Shadow 自身のトークンが消えるよう、トークンは引き継ぐ。
        $original->withAccessToken($shadow->currentAccessToken());
        Auth::setUser($original);
        $request->setUserResolver(fn () => $original);

        if ($request->routeIs('auth.logout')) {
            return $next($request);
        }

        if (! $request->isMethodSafe()) {
            return response()->json(['message' => 'Shadow users are read-only.', 'shadow' => true], 403);
        }

        DB::beginTransaction();
        try {
            return $next($request);
        } finally {
            DB::rollBack();
        }
    }
}

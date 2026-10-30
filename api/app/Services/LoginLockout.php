<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * ログイン失敗による一時ロック（総当たり対策）。
 *
 * throttle:login（AppServiceProvider）は「速さ」の上限で、5回/分を守れば1日に
 * 7,200回試せてしまう。こちらは「失敗の回数」を数え、一定数を超えたアカウントは
 * しばらく正しいパスワードでも入れなくする。成功すれば数え直し。
 *
 * - 実在しないメールでも同じように数える（ロックの有無でアカウントの実在が漏れないように）。
 * - Shadow は全員が同じパスワードなので、ログインIDを変えながら試されないよう
 *   全 Shadow で1つの枠を共有する。
 * - Tailscale 直結（100.64.0.0/10）のオーナーは数えず、ロックもされない。攻撃者が
 *   わざと失敗を重ねてオーナーを締め出す手を封じるため。公開経路（Funnel・本番）からは
 *   偽装できないアドレス（REMOTE_ADDR）だけで判断している。
 */
class LoginLockout
{
    /** [失敗回数の上限, 数える期間（秒）] */
    public const WINDOWS = [
        '15m' => [10, 15 * 60],
        '1d' => [50, 24 * 60 * 60],
    ];

    public function __construct(private ShadowUsers $shadows) {}

    public function bucketFor(string $email): string
    {
        return $this->shadows->isShadowAddress($email) ? 'shadow' : mb_strtolower(trim($email));
    }

    public function exempt(Request $request): bool
    {
        return IpUtils::checkIp((string) $request->ip(), '100.64.0.0/10');
    }

    /** ロック中なら解除までの秒数、そうでなければ 0。 */
    public function lockedFor(string $bucket): int
    {
        $wait = 0;
        foreach (self::WINDOWS as $name => [$max]) {
            $key = $this->key($bucket, $name);
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $wait = max($wait, RateLimiter::availableIn($key));
            }
        }

        return $wait;
    }

    public function recordFailure(string $bucket): void
    {
        foreach (self::WINDOWS as $name => [, $decay]) {
            RateLimiter::hit($this->key($bucket, $name), $decay);
        }
    }

    public function clear(string $bucket): void
    {
        foreach (array_keys(self::WINDOWS) as $name) {
            RateLimiter::clear($this->key($bucket, $name));
        }
    }

    private function key(string $bucket, string $window): string
    {
        return 'login-failures:'.$window.':'.sha1($bucket);
    }
}

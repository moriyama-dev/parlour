<?php

namespace App\Services;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Shadow ユーザーの作成・照合・掃除（CLAUDE.md §18）。
 *
 * Shadow は元ユーザーのデータを複製しない。users の1行として存在し、ログイン後は
 * ActAsShadowedUser ミドルウェアが「元ユーザーとして」リクエストを処理する。
 * 複製すると本人の画面と表示がずれていくため。
 */
class ShadowUsers
{
    public function enabled(): bool
    {
        return config('shadow.password') !== '';
    }

    public function emailFor(User $original): string
    {
        return 'shadow+'.$original->id.'@'.config('shadow.email_domain');
    }

    /** Shadow のログインIDの形をしているか（実在するかは問わない）。 */
    public function isShadowAddress(string $email): bool
    {
        $domain = preg_quote(config('shadow.email_domain'), '/');

        return (bool) preg_match('/^shadow\+\d+@'.$domain.'$/i', trim($email));
    }

    /** 登録・メール変更のバリデーション。Shadow のログインIDを普通の利用者に取らせない。 */
    public function reservedEmailRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (is_string($value) && preg_match('/^shadow\+/i', trim($value))) {
                $fail('This email address is reserved.');
            }
        };
    }

    public function shouldHave(User $user): bool
    {
        if (! $this->enabled() || ! in_array($user->role, ['developer', 'client'], true)) {
            return false;
        }

        $excluded = array_map('mb_strtolower', [
            ...config('shadow.excluded_emails', []),
            ...config('demo.accounts', []),
        ]);

        return ! in_array(mb_strtolower($user->email), $excluded, true);
    }

    public function ensureFor(User $original): ?User
    {
        if (! $this->shouldHave($original)) {
            return null;
        }
        if ($existing = User::where('shadow_of_id', $original->id)->first()) {
            return $existing;
        }

        $email = $this->emailFor($original);
        if (User::where('email', $email)->exists()) {
            Log::warning("Shadow user not created: {$email} is already taken by another user.");

            return null;
        }

        return User::create([
            'name' => $original->name.' (Shadow)',
            'email' => $email,
            // この行のパスワードでは入れない。ログインは SHADOW_PASSWORD との照合だけ（attempt）。
            'password' => Str::random(64),
            'role' => 'shadow',
            'shadow_of_id' => $original->id,
        ]);
    }

    /**
     * 足りない Shadow を作り、作るべきでない Shadow を消す。何度実行しても同じ結果になる。
     *
     * @return array{created: int, removed: int}
     */
    public function sync(): array
    {
        $created = 0;
        $removed = 0;

        User::whereIn('role', ['developer', 'client'])
            ->whereDoesntHave('shadow')
            ->get()
            ->each(function (User $user) use (&$created) {
                if ($this->ensureFor($user)) {
                    $created++;
                }
            });

        User::where('role', 'shadow')->with('original')->get()->each(function (User $shadow) use (&$removed) {
            if (! $shadow->original || ! $this->shouldHave($shadow->original)) {
                $shadow->delete();
                $removed++;
            }
        });

        return ['created' => $created, 'removed' => $removed];
    }

    /** Shadow のログイン。成功したら Shadow の行を返す（トークンはこの行に発行する）。 */
    public function attempt(string $email, string $password): ?User
    {
        $secret = (string) config('shadow.password');
        $matches = $secret !== '' && hash_equals($secret, $password);

        $shadow = User::where('email', mb_strtolower(trim($email)))
            ->where('role', 'shadow')
            ->with('original')
            ->first();

        return $matches && $shadow?->original ? $shadow : null;
    }
}

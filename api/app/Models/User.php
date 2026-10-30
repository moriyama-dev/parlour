<?php

namespace App\Models;

use App\Services\ShadowUsers;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'avatar_path',
        'notification_preferences',
        'shadow_of_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'notification_preferences' => 'array',
        ];
    }

    /**
     * 端末への通知（Web Push）を分類ごとにオン・オフできる。
     * ベルのお知らせ一覧はこの設定に関係なく残る（記録なので）。
     */
    public const NOTIFICATION_CATEGORIES = ['message', 'task', 'request', 'invoice', 'call', 'subscription'];

    /** 保存値に無い分類はオン。古い行（null）も全部オンになる。 */
    public function notificationPreferences(): array
    {
        $saved = $this->notification_preferences ?? [];

        return collect(self::NOTIFICATION_CATEGORIES)
            ->mapWithKeys(fn ($c) => [$c => (bool) ($saved[$c] ?? true)])
            ->all();
    }

    public function wantsPush(string $category): bool
    {
        return $this->notificationPreferences()[$category] ?? true;
    }

    // role enum values: developer, client, shadow（CLAUDE.md §18）

    protected static function booted(): void
    {
        // 登録された利用者には Shadow を自動で付ける（除外対象と機能無効時は何もしない）。
        static::created(fn (User $user) => app(ShadowUsers::class)->ensureFor($user));

        // Sanctum のトークンは多態関連で外部キーが無い。Shadow の行は外部キーで一緒に消えるが、
        // トークンは残るので、ここで明示的に破棄する（削除済み顧客の画面を見続けられないように）。
        static::deleting(function (User $user) {
            $user->tokens()->delete();
            $user->shadow?->tokens()->delete();
        });
    }

    public function isShadow(): bool
    {
        return $this->role === 'shadow';
    }

    /** Shadow から見た元ユーザー。 */
    public function original()
    {
        return $this->belongsTo(User::class, 'shadow_of_id');
    }

    /** 元ユーザーから見た Shadow。 */
    public function shadow()
    {
        return $this->hasOne(User::class, 'shadow_of_id');
    }

    public function companies()
    {
        return $this->belongsToMany(Company::class)->withPivot('is_primary')->withTimestamps();
    }

    public function projects()
    {
        return $this->hasManyThrough(Project::class, Company::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function pushSubscriptions()
    {
        return $this->hasMany(PushSubscription::class);
    }
}

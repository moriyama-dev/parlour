<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * 1台の端末（ブラウザ／ホーム画面アプリ）の通知の受け口。
 *
 * endpoint は配信サーバー（FCM・Apple など）が発行した URL で、そこへ POST すると
 * その端末に届く。public_key / auth_token は本文を暗号化するための端末側の鍵。
 */
class PushSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'endpoint',
        'endpoint_hash',
        'public_key',
        'auth_token',
        'device_name',
        'locale',
        'last_used_at',
    ];

    protected $hidden = [
        'public_key',
        'auth_token',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

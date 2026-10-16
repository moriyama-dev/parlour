<?php

namespace App\Models;

use App\Services\PushNotifier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'data',
        'read_at',
    ];

    /*
     * ベルに出るお知らせは、本人が切っていない限りスマホにも出す。
     * 作成箇所が各コントローラ・Webhook・cron に散らばっているので、ここ1か所で拾う。
     */
    protected static function booted(): void
    {
        static::created(fn (self $n) => app(PushNotifier::class)->forNotification($n));
    }

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

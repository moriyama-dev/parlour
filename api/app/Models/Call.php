<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Call extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'created_by',
        'title',
        'scheduled_at',
        'status',
        'started_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * ハートビートがこの秒数だけ途切れた参加者は、通話画面を閉じた（あるいは
     * 回線が落ちた）ものとして扱う。ポーリング間隔の数倍を取っている。
     */
    public const STALE_AFTER_SECONDS = 20;

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function participants()
    {
        return $this->hasMany(CallParticipant::class);
    }

    public function signals()
    {
        return $this->hasMany(CallSignal::class);
    }

    /**
     * 実際に今つながっている参加者。state だけでは足りない（ブラウザを強制終了
     * されると 'joined' のまま残る）ので、last_seen_at の新しさも条件に入れる。
     */
    public function liveParticipants()
    {
        return $this->participants()
            ->where('state', 'joined')
            ->where('last_seen_at', '>=', now()->subSeconds(self::STALE_AFTER_SECONDS));
    }

    public function isOver(): bool
    {
        return in_array($this->status, ['ended', 'cancelled'], true);
    }

    /**
     * 誰も居なくなったまま残っている通話を閉じる。
     *
     * ブラウザのタブを閉じられると leave が飛んでこないので、last_seen_at の
     * 古さだけを根拠に判定する。これを回さないと、放置された通話が招待者の画面で
     * いつまでも鳴り続ける。
     */
    public static function sweepAbandoned(): void
    {
        /*
         * 開始時刻を過ぎたまま誰も入らなかった予約通話を畳む。
         *
         * scheduled_at を過去にすると CallController@store の二重発信ガード
         * （live かつ scheduled_at が null の通話だけを再利用する）をすり抜けられ、
         * /calls/ringing の窓に入る行をいくらでも作れてしまう。行数そのものは
         * throttle:calls-create が抑えるが、作られてしまった行はどの掃除にも
         * 引っかからず永久に残り、相手の画面で鳴り続ける。
         */
        static::where('status', 'scheduled')
            ->where('scheduled_at', '<', now()->subHour())
            ->update(['status' => 'cancelled', 'ended_at' => now()]);

        $stale = static::where('status', 'live')
            ->whereDoesntHave('participants', function ($q) {
                $q->where('state', 'joined')
                    ->where('last_seen_at', '>=', now()->subSeconds(self::STALE_AFTER_SECONDS));
            })
            // 作った直後（まだ誰も入っていない発信中）を閉じないための猶予。
            ->where('started_at', '<=', now()->subMinute())
            ->get();

        foreach ($stale as $call) {
            $call->update(['status' => 'ended', 'ended_at' => now()]);
            $call->signals()->delete();
            $call->participants()
                ->where('state', 'joined')
                ->update(['state' => 'left', 'left_at' => now()]);
        }
    }
}

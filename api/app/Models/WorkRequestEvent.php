<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 依頼の履歴1行。種類（type）:
 *   created / comment / started / delivered / revision_requested / approved /
 *   on_hold / cancelled / time（作業時間の記録・internal）/ note（開発者メモ・internal）
 */
class WorkRequestEvent extends Model
{
    protected $fillable = [
        'work_request_id',
        'user_id',
        'type',
        'body',
        'meta',
        'minutes',
        'internal',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'minutes' => 'integer',
            'internal' => 'boolean',
        ];
    }

    public function workRequest()
    {
        return $this->belongsTo(WorkRequest::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

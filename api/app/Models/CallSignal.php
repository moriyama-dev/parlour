<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CallSignal extends Model
{
    use HasFactory;

    /**
     * シグナルは追記のみで更新しない。updated_at は要らないので落とす。
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'call_id',
        'from_user_id',
        'to_user_id',
        'type',
        'payload',
    ];

    public function call()
    {
        return $this->belongsTo(Call::class);
    }
}

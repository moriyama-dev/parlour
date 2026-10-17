<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 顧客からの紹介（お隣・同業の会社）。status は開発者が営業の進み具合で動かす。 */
class Referral extends Model
{
    public const STATUSES = ['new', 'contacted', 'won', 'lost'];

    protected $fillable = [
        'company_id',
        'user_id',
        'referred_name',
        'contact_name',
        'contact_email',
        'contact_phone',
        'note',
        'status',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

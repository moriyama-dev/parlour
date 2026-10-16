<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'title',
        'description',
        'status',
        'staging_url',
        'production_url',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function tasks()
    {
        return $this->hasMany(Task::class);
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    /** この案件に関われる人（開発者全員＋発注元の会社の顧客）。EnsureProjectAccess と同じ基準。 */
    public function members()
    {
        // 括弧でくくる。続けて where を足したときに OR の外へ漏れないように。
        return User::query()->where(fn ($q) => $q
            ->where('role', 'developer')
            ->orWhereHas('companies', fn ($c) => $c->whereKey($this->company_id)));
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function calls()
    {
        return $this->hasMany(Call::class);
    }
}

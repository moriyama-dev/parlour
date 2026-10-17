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
        'brief',
        'status',
        'staging_url',
        'production_url',
    ];

    /**
     * 案件のヒアリング項目（Conte の 20 分ディスカバリーで聞くこと）。
     * 顧客が案件を作るときに書く。budget だけは BUDGET_BANDS から選ぶ。
     */
    public const BRIEF_FIELDS = ['business', 'goals', 'audience', 'budget', 'deadline', 'references', 'competitors', 'must_haves'];

    /** 予算の帯（CAD）。最低帯を見せること自体が、話す前の足切りになる。 */
    public const BUDGET_BANDS = ['under_2500', '2500_5000', '5000_10000', '10000_plus', 'undecided'];

    protected function casts(): array
    {
        return [
            'brief' => 'array',
        ];
    }

    public function workRequests()
    {
        return $this->hasMany(WorkRequest::class);
    }

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

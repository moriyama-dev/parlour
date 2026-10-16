<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * ホワイトボード（「イメージ」メニュー）。
 *
 * 中身はブラウザ側の JSON（要素の配列）をそのまま預かるだけで、サーバーは
 * 構造を解釈しない。誰に見えるかだけがサーバーの責任。
 *
 *   - 作成者は常に見える。
 *   - 開発者（スタジオ側）は全ボードが見える。
 *   - 顧客は、自社の案件に紐づいたボードだけ追加で見える（project_id）。
 */
class Board extends Model
{
    use HasFactory;

    protected $attributes = [
        'is_starred' => false,
    ];

    protected $fillable = [
        'user_id',
        'project_id',
        'title',
        'content',
        'thumbnail',
        'is_starred',
        'last_opened_at',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'is_starred' => 'boolean',
            'last_opened_at' => 'datetime',
        ];
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role === 'developer') {
            return $query;
        }

        $companyIds = $user->companies()->pluck('companies.id');

        return $query->where(function (Builder $q) use ($user, $companyIds) {
            $q->where('user_id', $user->id)
                ->orWhereHas('project', fn (Builder $p) => $p->whereIn('company_id', $companyIds));
        });
    }

    public function isVisibleTo(User $user): bool
    {
        if ($user->role === 'developer' || $this->user_id === $user->id) {
            return true;
        }

        if (! $this->project_id) {
            return false;
        }

        $companyId = $this->project()->value('company_id');

        return $companyId !== null
            && $user->companies()->whereKey($companyId)->exists();
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->user_id === $user->id;
    }
}

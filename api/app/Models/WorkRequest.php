<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * 依頼キューの1件。顧客の「これを変えてほしい」「これを足してほしい」。
 *
 * 状態の流れ:
 *
 *   queued ──start──▶ in_progress ──deliver──▶ client_review ──approve──▶ done
 *     ▲                    ▲                        │
 *     │                    └──deliver── revision ◀──┘ (顧客が修正を依頼。回数は無制限)
 *     └──────── on_hold（保留。start で再開）
 *
 *   cancelled は done 以外のどこからでも。
 *
 * 会社の同時進行数（Company::queue_active_limit）を食うのは ACTIVE_STATUSES だけ。
 * client_review は顧客の手番なので枠を空ける（確認待ちの間に次へ進める）。
 */
class WorkRequest extends Model
{
    use HasFactory;

    public const CATEGORIES = ['change', 'bug', 'new_page', 'content', 'design', 'seo', 'other'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const STATUSES = ['queued', 'in_progress', 'client_review', 'revision', 'on_hold', 'done', 'cancelled'];

    /** 同時進行数の枠を使う状態。 */
    public const ACTIVE_STATUSES = ['in_progress', 'revision'];

    /** まだ終わっていない状態。 */
    public const OPEN_STATUSES = ['queued', 'in_progress', 'client_review', 'revision', 'on_hold'];

    protected $fillable = [
        'company_id',
        'project_id',
        'created_by',
        'assignee_id',
        'title',
        'description',
        'category',
        'priority',
        'status',
        'position',
        'reference_url',
        'video_url',
        'requested_due_on',
        'estimate_hours',
        'spent_minutes',
        'checklist',
        'delivery_note',
        'delivery_url',
        'due_at',
        'started_at',
        'delivered_at',
        'completed_at',
        'revision_count',
        'rating',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'requested_due_on' => 'date',
            'estimate_hours' => 'float',
            'spent_minutes' => 'integer',
            'checklist' => 'array',
            'due_at' => 'datetime',
            'started_at' => 'datetime',
            'delivered_at' => 'datetime',
            'completed_at' => 'datetime',
            'revision_count' => 'integer',
            'rating' => 'integer',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function events()
    {
        return $this->hasMany(WorkRequestEvent::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** 作業中なのに納期目安を過ぎている。 */
    public function isOverdue(): bool
    {
        return $this->isActive() && $this->due_at !== null && $this->due_at->isPast();
    }

    /** この利用者に見えてよい依頼。顧客は自分の所属会社のものだけ。 */
    public static function visibleTo(User $user): Builder
    {
        if ($user->role === 'developer') {
            return self::query();
        }

        return self::query()->whereIn('company_id', $user->companies()->pluck('companies.id'));
    }

    /** 履歴に1行足す。 */
    public function log(string $type, ?User $user = null, ?string $body = null, array $meta = [], bool $internal = false, ?int $minutes = null): WorkRequestEvent
    {
        return $this->events()->create([
            'user_id' => $user?->id,
            'type' => $type,
            'body' => $body,
            'meta' => $meta ?: null,
            'internal' => $internal,
            'minutes' => $minutes,
        ]);
    }

    /**
     * お知らせを出す。$to は 'clients'（依頼元の会社の顧客全員）か 'developers'（全開発者）。
     * 操作した本人には出さない。
     */
    public function notify(string $type, string $to, ?User $actor = null, array $data = []): void
    {
        $recipients = $to === 'developers'
            ? User::where('role', 'developer')->pluck('id')
            : $this->company->users()->where('role', 'client')->pluck('users.id');

        $data = [
            'request_id' => $this->id,
            'request_title' => $this->title,
            'company_id' => $this->company_id,
            'company_name' => $this->company->name,
            'actor_name' => $actor?->name,
            ...$data,
        ];

        foreach ($recipients->unique() as $userId) {
            if ($actor && $userId === $actor->id) {
                continue;
            }
            Notification::create(['user_id' => $userId, 'type' => $type, 'data' => $data]);
        }
    }
}

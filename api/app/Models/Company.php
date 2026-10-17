<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'website',
        'timezone',
        // 依頼キューの約束事（RequestQueueController だけが書く）
        'queue_active_limit',
        'queue_turnaround_hours',
        'queue_monthly_hours',
        'queue_paused_at',
        'queue_pause_reason',
    ];

    protected function casts(): array
    {
        return [
            'queue_active_limit' => 'integer',
            'queue_turnaround_hours' => 'integer',
            'queue_monthly_hours' => 'float',
            'queue_paused_at' => 'datetime',
        ];
    }

    public function users()
    {
        return $this->belongsToMany(User::class)->withPivot('is_primary')->withTimestamps();
    }

    public function projects()
    {
        return $this->hasMany(Project::class);
    }

    public function invitations()
    {
        return $this->hasMany(Invitation::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function workRequests()
    {
        return $this->hasMany(WorkRequest::class);
    }

    /** 開発者全員と、この会社に所属する顧客。EnsureProjectAccess と同じ基準。 */
    public function isVisibleTo(User $user): bool
    {
        return $user->role === 'developer' || $user->companies()->whereKey($this->id)->exists();
    }

    public function queueIsPaused(): bool
    {
        return $this->queue_paused_at !== null;
    }
}

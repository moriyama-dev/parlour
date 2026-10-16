<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 顧客企業ごとの定期契約。
 *
 * status は 'pending'（顧客の支払い登録待ち）以外、Stripe の subscription.status の写し。
 */
class Subscription extends Model
{
    public const INTERVALS = ['month', 'year'];

    /** これらの間は Stripe がまだ課金を続ける（または続けようとしている）。 */
    public const LIVE_STATUSES = ['trialing', 'active', 'past_due', 'unpaid', 'paused', 'incomplete'];

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'amount_cents',
        'currency',
        'interval',
        'status',
        'stripe_subscription_id',
        'stripe_item_id',
        'stripe_product_id',
        'stripe_price_id',
        'started_at',
        'current_period_end',
        'cancel_at_period_end',
        'canceled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'started_at' => 'datetime',
            'current_period_end' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'canceled_at' => 'datetime',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function payments()
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    /** Stripe 側にまだ実体が無い（顧客がカードを登録していない）。 */
    public function isPending(): bool
    {
        return $this->stripe_subscription_id === null;
    }

    /**
     * 支払い登録（Stripe Checkout）に進めるか。登録前か、登録途中で期限切れになったもの。
     */
    public function awaitsCheckout(): bool
    {
        return in_array($this->status, ['pending', 'incomplete_expired'], true);
    }

    /**
     * 課金が続いている契約か。status だけで決める（Stripe の id の有無は見ない）。
     * id で絞ると、Stripe と紐づいていないデモの架空データや、Webhook で id が届く前の
     * 一瞬の行が「有効」に数えられず、集計が 0 になる。'pending' は LIVE_STATUSES に無い。
     */
    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE_STATUSES, true);
    }

    /** この利用者に見えてよい契約。顧客は自分の所属会社のものだけ。 */
    public static function visibleTo(User $user): Builder
    {
        if ($user->role === 'developer') {
            return self::query();
        }

        return self::query()->whereIn('company_id', $user->companies()->pluck('companies.id'));
    }

    /** 契約先の顧客全員と、全開発者にお知らせを出す。 */
    public function notify(string $type, array $data = [], bool $developers = false, bool $clients = true): void
    {
        $data = ['subscription_id' => $this->id, 'subscription_name' => $this->name, ...$data];

        $recipients = collect();
        if ($clients) {
            $recipients = $recipients->merge($this->company->users()->pluck('users.id'));
        }
        if ($developers) {
            $recipients = $recipients->merge(User::where('role', 'developer')->pluck('id'));
        }

        foreach ($recipients->unique() as $userId) {
            Notification::create(['user_id' => $userId, 'type' => $type, 'data' => $data]);
        }
    }
}

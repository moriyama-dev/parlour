<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReferralResource;
use App\Http\Resources\TestimonialResource;
use App\Models\Company;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Referral;
use App\Models\Testimonial;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * 納品後の推薦文と紹介。
 *
 * Conte は納品のたびに実名の推薦文を回収し、同じ系列・同業へ紹介で広げて 450 件まで来た。
 * ここはその受け口。書くのは顧客、読むのは開発者（顧客は自社が出したものだけ見える）。
 */
class FeedbackController extends Controller
{
    public function testimonials(Request $request)
    {
        return TestimonialResource::collection(
            $this->scoped(Testimonial::query(), $request->user())->with(['company', 'project', 'user'])->latest()->get()
        );
    }

    public function storeTestimonial(Request $request)
    {
        $user = $request->user();
        abort_unless($user->role === 'client', 403, 'Forbidden');

        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'body' => ['required', 'string', 'max:3000'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'allow_publish' => ['boolean'],
        ]);

        $company = $this->ownCompany($user, $validated['company_id']);
        if (! empty($validated['project_id'])) {
            abort_unless(Project::whereKey($validated['project_id'])->where('company_id', $company->id)->exists(), 422, 'The project does not belong to this company.');
        }

        $testimonial = Testimonial::create([...$validated, 'user_id' => $user->id, 'allow_publish' => $validated['allow_publish'] ?? false]);
        $this->notifyDevelopers('testimonial_received', $company, $user, [
            'rating' => $testimonial->rating,
            'excerpt' => Str::limit(preg_replace('/\s+/u', ' ', $testimonial->body), 80),
        ]);

        return new TestimonialResource($testimonial->load(['company', 'project', 'user']));
    }

    public function referrals(Request $request)
    {
        return ReferralResource::collection(
            $this->scoped(Referral::query(), $request->user())->with(['company', 'user'])->latest()->get()
        );
    }

    public function storeReferral(Request $request)
    {
        $user = $request->user();
        abort_unless($user->role === 'client', 403, 'Forbidden');

        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'referred_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $company = $this->ownCompany($user, $validated['company_id']);
        $referral = Referral::create([...$validated, 'user_id' => $user->id, 'status' => 'new']);
        $this->notifyDevelopers('referral_received', $company, $user, ['referred_name' => $referral->referred_name]);

        return new ReferralResource($referral->load(['company', 'user']));
    }

    /** 紹介の営業の進み具合（開発者）。 */
    public function updateReferral(Request $request, Referral $referral)
    {
        $validated = $request->validate(['status' => ['required', Rule::in(Referral::STATUSES)]]);
        $referral->update($validated);

        return new ReferralResource($referral->load(['company', 'user']));
    }

    private function scoped($query, User $user)
    {
        return $user->role === 'developer'
            ? $query
            : $query->whereIn('company_id', $user->companies()->pluck('companies.id'));
    }

    /** company_id はリクエストから来るので、所属の照合はサーバー側で必ず行う。 */
    private function ownCompany(User $user, int $companyId): Company
    {
        $company = $user->companies()->whereKey($companyId)->first();
        abort_unless($company !== null, 403, 'Forbidden');

        return $company;
    }

    private function notifyDevelopers(string $type, Company $company, User $actor, array $data): void
    {
        foreach (User::where('role', 'developer')->pluck('id') as $userId) {
            Notification::create([
                'user_id' => $userId,
                'type' => $type,
                'data' => ['company_id' => $company->id, 'company_name' => $company->name, 'actor_name' => $actor->name, ...$data],
            ]);
        }
    }
}

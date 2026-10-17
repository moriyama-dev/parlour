<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 推薦文・紹介・案件のヒアリング。どれも顧客が書く欄なので、名義と中身の上限を固定する。 */
class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_client_leaves_a_testimonial_and_a_referral_for_their_own_company(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        $client = User::factory()->client()->create();
        $client->companies()->attach($company->id);
        $dev = User::factory()->developer()->create();

        $this->actingAs($client)->postJson('/testimonials', [
            'company_id' => $company->id, 'rating' => 5, 'body' => 'Fast and careful.', 'allow_publish' => true,
        ])->assertCreated();
        $this->actingAs($client)->postJson('/testimonials', [
            'company_id' => $other->id, 'rating' => 5, 'body' => 'Fake.',
        ])->assertForbidden();

        $this->actingAs($client)->postJson('/referrals', [
            'company_id' => $company->id, 'referred_name' => 'Kitchen Refinish Co.', 'contact_email' => 'owner@example.com',
        ])->assertCreated()->assertJsonPath('data.status', 'new');

        $this->actingAs($dev)->getJson('/testimonials')->assertOk()->assertJsonCount(1, 'data');
        $referral = $this->actingAs($dev)->getJson('/referrals')->assertOk()->json('data.0');
        $this->actingAs($dev)->patchJson("/referrals/{$referral['id']}", ['status' => 'won'])->assertOk();
        $this->actingAs($client)->patchJson("/referrals/{$referral['id']}", ['status' => 'lost'])->assertForbidden();

        // 開発者は推薦文を書けない（自作自演の防止）
        $this->actingAs($dev)->postJson('/testimonials', [
            'company_id' => $company->id, 'rating' => 5, 'body' => 'x',
        ])->assertForbidden();
    }

    public function test_the_project_brief_keeps_only_known_fields(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->client()->create();
        $client->companies()->attach($company->id);

        $this->actingAs($client)->postJson('/projects', [
            'company_id' => $company->id,
            'title' => 'New site',
            'brief' => ['goals' => 'More bookings', 'budget' => '5000_10000', 'deadline' => '2026-12-01'],
        ])->assertCreated()->assertJsonPath('data.brief.budget', '5000_10000');

        $this->actingAs($client)->postJson('/projects', [
            'company_id' => $company->id, 'title' => 'x', 'brief' => ['payload' => str_repeat('a', 10)],
        ])->assertStatus(422);
        $this->actingAs($client)->postJson('/projects', [
            'company_id' => $company->id, 'title' => 'x', 'brief' => ['budget' => 'a million'],
        ])->assertStatus(422);
    }

    public function test_invoice_line_items_are_saved(): void
    {
        $dev = User::factory()->developer()->create();
        $project = Project::factory()->create();

        $this->actingAs($dev)->postJson("/projects/{$project->id}/invoices", [
            'subtotal' => 1500, 'tax_amount' => 150, 'total' => 1650, 'tax_rate' => 10,
            'items' => [
                ['description' => 'Deposit (50%)', 'quantity' => 1, 'unit_price' => 1000],
                ['description' => 'Hosting', 'quantity' => 2, 'unit_price' => 250],
            ],
        ])->assertCreated()->assertJsonCount(2, 'data.items');

        $this->assertDatabaseHas('invoice_items', ['description' => 'Hosting', 'amount' => 500]);
    }
}

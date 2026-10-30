<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Referral;
use App\Models\Testimonial;
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
        $this->actingAs($dev)->patchJson("/referrals/{$referral['id']}/status", ['status' => 'won'])->assertOk();
        $this->actingAs($client)->patchJson("/referrals/{$referral['id']}/status", ['status' => 'lost'])->assertForbidden();

        // 開発者は推薦文を書けない（自作自演の防止）
        $this->actingAs($dev)->postJson('/testimonials', [
            'company_id' => $company->id, 'rating' => 5, 'body' => 'x',
        ])->assertForbidden();
    }

    public function test_only_the_author_can_edit_or_delete_a_testimonial(): void
    {
        $company = Company::factory()->create();
        $author = User::factory()->client()->create();
        $colleague = User::factory()->client()->create();
        $author->companies()->attach($company->id);
        $colleague->companies()->attach($company->id);
        $dev = User::factory()->developer()->create();

        $id = $this->actingAs($author)->postJson('/testimonials', [
            'company_id' => $company->id, 'rating' => 5, 'body' => 'Great.', 'allow_publish' => true,
        ])->json('data.id');

        // 同じ会社の別の人も、開発者も、直せないし消せない
        $this->actingAs($colleague)->patchJson("/testimonials/{$id}", ['body' => 'Hijacked'])->assertNotFound();
        $this->actingAs($colleague)->deleteJson("/testimonials/{$id}")->assertNotFound();
        $this->actingAs($dev)->patchJson("/testimonials/{$id}", ['rating' => 1])->assertNotFound();
        $this->actingAs($dev)->deleteJson("/testimonials/{$id}")->assertNotFound();

        $this->actingAs($author)->patchJson("/testimonials/{$id}", ['rating' => 4, 'body' => 'Great, and fast.', 'allow_publish' => false])
            ->assertOk()->assertJsonPath('data.rating', 4)->assertJsonPath('data.allow_publish', false);
        $this->assertTrue(Notification::where('user_id', $dev->id)->where('type', 'testimonial_updated')->exists());

        // 他社の案件には付け替えられない
        $foreign = Project::factory()->create();
        $this->actingAs($author)->patchJson("/testimonials/{$id}", ['project_id' => $foreign->id])->assertStatus(422);

        $this->actingAs($author)->deleteJson("/testimonials/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('testimonials', ['id' => $id]);
        $this->assertTrue(Notification::where('user_id', $dev->id)->where('type', 'testimonial_deleted')->exists());
    }

    public function test_a_referral_can_be_changed_by_its_author_only_until_we_follow_up(): void
    {
        $company = Company::factory()->create();
        $author = User::factory()->client()->create();
        $colleague = User::factory()->client()->create();
        $author->companies()->attach($company->id);
        $colleague->companies()->attach($company->id);
        $dev = User::factory()->developer()->create();

        $id = $this->actingAs($author)->postJson('/referrals', [
            'company_id' => $company->id, 'referred_name' => 'Kitchen Co.',
        ])->json('data.id');

        $this->actingAs($colleague)->patchJson("/referrals/{$id}", ['note' => 'x'])->assertNotFound();
        $this->actingAs($dev)->deleteJson("/referrals/{$id}")->assertNotFound();

        // 状態は本人の編集では変わらない（開発者の専用経路だけ）
        $this->actingAs($author)->patchJson("/referrals/{$id}", ['contact_email' => 'owner@example.com', 'status' => 'won'])
            ->assertOk()->assertJsonPath('data.contact_email', 'owner@example.com')->assertJsonPath('data.status', 'new');
        $this->actingAs($author)->patchJson("/referrals/{$id}", ['contact_email' => 'not-an-email'])->assertStatus(422);

        // こちらが連絡を始めたら、営業の記録として固定する
        $this->actingAs($dev)->patchJson("/referrals/{$id}/status", ['status' => 'contacted'])->assertOk();
        $this->actingAs($author)->patchJson("/referrals/{$id}", ['note' => 'late edit'])->assertStatus(409);
        $this->actingAs($author)->deleteJson("/referrals/{$id}")->assertStatus(409);

        $fresh = Referral::create(['company_id' => $company->id, 'user_id' => $author->id, 'referred_name' => 'B', 'status' => 'new']);
        $this->actingAs($author)->deleteJson("/referrals/{$fresh->id}")->assertNoContent();
        $this->assertDatabaseMissing('referrals', ['id' => $fresh->id]);
        $this->assertSame(0, Testimonial::count());
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

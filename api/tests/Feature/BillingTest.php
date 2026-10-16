<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 「料金」ページが読む横断エンドポイント。
 *
 * 案件をまたぐぶん、案件ごとのミドルウェア（project.access）が効かない場所なので、
 * 誰の請求書が誰に見えるかはここで固定しておく。
 */
class BillingTest extends TestCase
{
    use RefreshDatabase;

    private function insider(Project $project): User
    {
        $client = User::factory()->client()->create();
        $client->companies()->attach($project->company_id);

        return $client;
    }

    private function invoice(Project $project, string $status, string $number): Invoice
    {
        return Invoice::create([
            'project_id' => $project->id,
            'company_id' => $project->company_id,
            'invoice_number' => $number,
            'status' => $status,
            'issued_at' => now()->subDays(10),
            'due_at' => now()->addDays(20),
            'subtotal' => 100000,
            'tax_rate' => 10,
            'tax_amount' => 10000,
            'total' => 110000,
        ]);
    }

    public function test_a_client_sees_only_their_own_companys_sent_invoices(): void
    {
        $mine = Project::factory()->create();
        $theirs = Project::factory()->create();
        $client = $this->insider($mine);

        $sent = $this->invoice($mine, 'sent', 'INV-A');
        $draft = $this->invoice($mine, 'draft', 'INV-B');
        $other = $this->invoice($theirs, 'sent', 'INV-C');

        $res = $this->actingAs($client)->getJson('/billing/invoices')->assertOk();
        $this->assertSame(['INV-A'], array_column($res->json('data'), 'invoice_number'));

        // 下書きと他社宛は個別に叩いても届かない。
        $this->actingAs($client)->getJson("/billing/invoices/{$draft->id}")->assertForbidden();
        $this->actingAs($client)->getJson("/billing/invoices/{$other->id}")->assertForbidden();
        $this->actingAs($client)->getJson("/billing/invoices/{$sent->id}")->assertOk();
    }

    public function test_a_developer_sees_every_invoice_including_drafts(): void
    {
        $dev = User::factory()->developer()->create();
        $project = Project::factory()->create();
        $this->invoice($project, 'draft', 'INV-D');
        $this->invoice($project, 'paid', 'INV-E');

        $this->actingAs($dev)->getJson('/billing/invoices')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_paid_invoice_carries_a_receipt_number_and_payment_date(): void
    {
        $dev = User::factory()->developer()->create();
        $project = Project::factory()->create();
        $invoice = $this->invoice($project, 'sent', 'INV-2026-001');

        $this->actingAs($dev)
            ->patchJson("/projects/{$project->id}/invoices/{$invoice->id}/paid")
            ->assertOk()
            ->assertJsonPath('data.receipt_number', 'RCT-2026-001');

        $this->assertNotNull($invoice->fresh()->paid_at);

        // 未払いのものに領収書番号は出ない。
        $unpaid = $this->invoice($project, 'sent', 'INV-2026-002');
        $this->actingAs($dev)->getJson("/billing/invoices/{$unpaid->id}")
            ->assertOk()
            ->assertJsonPath('data.receipt_number', null);
    }

    public function test_marking_paid_twice_keeps_the_first_payment_date(): void
    {
        $dev = User::factory()->developer()->create();
        $project = Project::factory()->create();
        $invoice = $this->invoice($project, 'sent', 'INV-F');

        $this->actingAs($dev)->patchJson("/projects/{$project->id}/invoices/{$invoice->id}/paid")->assertOk();
        $first = $invoice->fresh()->paid_at;

        $this->travel(2)->days();
        $this->actingAs($dev)->patchJson("/projects/{$project->id}/invoices/{$invoice->id}/paid")->assertOk();

        $this->assertTrue($first->equalTo($invoice->fresh()->paid_at));
    }
}

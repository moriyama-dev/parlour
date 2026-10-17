<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\Project;
use App\Models\Referral;
use App\Models\Subscription;
use App\Models\Task;
use App\Models\Testimonial;
use App\Models\User;
use App\Models\WorkRequest;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * 採用担当・見学者向けの公開デモアカウントとサンプルデータを投入する。
 *
 * - 冪等（re-runnable）: メール/slug/invoice_number をキーに updateOrCreate し、
 *   デモ用プロジェクト配下の子レコードは毎回作り直す。実行のたびに増殖しない。
 * - サンプル文言は英語（デモの主目的が英語圏の採用担当のため）。
 *
 * 本番反映は手動:  php artisan db:seed --class=DemoSeeder --force
 *
 * ⚠ developer ロールは代理店ビューで全社を横断的に見られる。本番に実クライアント
 *   データがある場合、開発者デモ資格情報を公開するとそれが見えてしまう点に注意
 *   （その場合はログイン画面ではクライアントデモのみ公開する運用を推奨）。
 */
class DemoSeeder extends Seeder
{
    /** ログイン画面に表示する内容と一致させること。 */
    public const DEV_EMAIL = 'demo-dev@parlour.takumi.ca';

    public const CLIENT_EMAIL = 'demo-client@parlour.takumi.ca';

    // これらを変えたら config/demo.php の 'accounts' も必ず合わせること。
    // ログイン側はそちらのリストを見て、デモホスト以外でのデモ資格情報を拒否する。
    public const PASSWORD = 'demoparlour';

    public function run(): void
    {
        // 安全ガード: デモモードでない環境（＝本番）では絶対に架空データを入れない。
        // 本番 .env は DEMO_MODE 未設定 → ここで停止する。
        if (! config('app.demo_mode')) {
            $this->command?->warn('DEMO_MODE is not enabled — refusing to seed demo data. Set DEMO_MODE=true only on the demo site.');

            return;
        }

        DB::transaction(function () {
            $developer = User::updateOrCreate(
                ['email' => self::DEV_EMAIL],
                [
                    'name' => 'Yoshiro Moriyama (Demo)',
                    'password' => Hash::make(self::PASSWORD),
                    'role' => 'developer',
                    'email_verified_at' => now(),
                ]
            );

            $client = User::updateOrCreate(
                ['email' => self::CLIENT_EMAIL],
                [
                    'name' => 'Aoi Tanaka (Demo Client)',
                    'password' => Hash::make(self::PASSWORD),
                    'role' => 'client',
                    'email_verified_at' => now(),
                ]
            );

            $company = Company::updateOrCreate(
                ['slug' => 'demo-sakura-trading'],
                [
                    'name' => 'Sakura Trading Co. (Demo)',
                    'website' => 'https://example.com',
                    'timezone' => 'America/Toronto',
                    // 依頼キューの約束事（VIP Starter 相当：同時1件・3営業日・月4時間）
                    'queue_active_limit' => 1,
                    'queue_turnaround_hours' => 72,
                    'queue_monthly_hours' => 4,
                    'queue_paused_at' => null,
                    'queue_pause_reason' => null,
                ]
            );

            // ピボット: クライアントを主担当、開発者も紐付け
            $company->users()->syncWithoutDetaching([
                $client->id => ['is_primary' => true],
                $developer->id => ['is_primary' => false],
            ]);

            $project = Project::updateOrCreate(
                ['company_id' => $company->id, 'title' => 'Corporate Website Renewal'],
                [
                    'description' => 'Full redesign and rebuild of the corporate site, with a staging review workflow and monthly maintenance.',
                    'status' => 'active',
                    'staging_url' => 'https://staging.example.com',
                    'production_url' => 'https://example.com',
                    'brief' => [
                        'business' => 'Importer of Japanese tableware and tea, selling to restaurants and retail shops across Ontario.',
                        'goals' => 'Get more wholesale inquiries from restaurants, and let existing buyers reorder without phoning.',
                        'audience' => 'Restaurant owners and buyers at gift and kitchenware shops.',
                        'budget' => '5000_10000',
                        'deadline' => now()->addMonths(2)->toDateString(),
                        'references' => 'https://example.com/tableware-reference',
                        'must_haves' => 'Bilingual (EN/JA), wholesale inquiry form, product catalogue that staff can update.',
                    ],
                ]
            );

            // デモ用プロジェクト配下は毎回作り直す（冪等）
            $project->tasks()->delete();
            $project->messages()->delete();
            foreach ($project->invoices as $inv) {
                $inv->items()->delete();
            }
            $project->invoices()->delete();

            $tasks = [
                ['title' => 'Homepage design review', 'type' => 'design_review', 'status' => 'pending_review', 'due' => 5],
                ['title' => 'Staging review: product pages', 'type' => 'staging_review', 'status' => 'approved', 'due' => -2],
                ['title' => 'Deploy approval: go-live', 'type' => 'deploy_approval', 'status' => 'pending_review', 'due' => 7],
                ['title' => 'Dependency updates (June)', 'type' => 'dependency_update', 'status' => 'deployed', 'due' => -6],
                ['title' => 'Content revision: About page', 'type' => 'content_revision', 'status' => 'rejected', 'due' => -1],
            ];
            $createdTasks = [];
            foreach ($tasks as $t) {
                $createdTasks[] = Task::create([
                    'project_id' => $project->id,
                    'created_by' => $developer->id,
                    'title' => $t['title'],
                    'description' => 'Demo task — review the staging build and approve or request changes.',
                    'type' => $t['type'],
                    'status' => $t['status'],
                    'staging_url' => 'https://staging.example.com',
                    'due_date' => now()->addDays($t['due'])->toDateString(),
                ]);
            }

            $invoice = Invoice::create([
                'project_id' => $project->id,
                'company_id' => $company->id,
                'invoice_number' => 'INV-DEMO-001',
                'status' => 'sent',
                'issued_at' => now()->toDateString(),
                'due_at' => now()->addDays(14)->toDateString(),
                'subtotal' => 480000,
                'tax_rate' => 10,
                'tax_amount' => 48000,
                'total' => 528000,
                'notes' => 'Thank you for your business.',
            ]);
            $invoice->items()->createMany([
                ['description' => 'Website renewal — design & build', 'quantity' => 1, 'unit_price' => 450000, 'amount' => 450000],
                ['description' => 'Monthly maintenance (June)', 'quantity' => 1, 'unit_price' => 30000, 'amount' => 30000],
            ]);

            // サブスク。デモには Stripe の鍵が無いので、支払い登録ボタンは「デモでは無効」と出る。
            // 稼働中・登録待ちの両方を置き、どちらの画面も見られるようにする。
            $company->subscriptions()->delete(); // 支払い履歴は外部キーの cascade で消える
            // 日付は「今の期間の終わり」から逆算して、開始日・履歴・次回請求日を噛み合わせる。
            // 正午にしておくのは、UTC の 0 時だとトロント時間で前日に表示されるため。
            $periodEnd = now()->addDays(12)->setTime(12, 0);
            $hosting = Subscription::create([
                'company_id' => $company->id,
                'name' => 'Hosting & maintenance',
                'description' => 'Managed hosting, daily backups, security updates and up to 2 hours of small edits per month.',
                'amount_cents' => 14900,
                'currency' => 'cad',
                'interval' => 'month',
                'status' => 'active',
                'started_at' => $periodEnd->copy()->subMonths(3),
                'current_period_end' => $periodEnd,
            ]);
            // 開始から今の期間まで3回分（最新は今の期間＝次回請求日で終わるもの）。
            foreach ([3, 2, 1] as $i => $monthsBefore) {
                $start = $periodEnd->copy()->subMonths($monthsBefore);
                $hosting->payments()->create([
                    'number' => 'DEMO-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                    'status' => 'paid',
                    'amount_due' => 14900,
                    'amount_paid' => 14900,
                    'currency' => 'cad',
                    'period_start' => $start,
                    'period_end' => $start->copy()->addMonth(),
                    'paid_at' => $start,
                ]);
            }
            Subscription::create([
                'company_id' => $company->id,
                'name' => 'Domain & SSL (annual)',
                'description' => 'Domain renewal and SSL certificate management.',
                'amount_cents' => 9000,
                'currency' => 'cad',
                'interval' => 'year',
                'status' => 'pending',
            ]);

            $this->seedRequestQueue($company, $project, $developer, $client);

            Message::create([
                'project_id' => $project->id,
                'user_id' => $developer->id,
                'body' => 'Hi! The new homepage is on staging now. Could you take a look and let me know what you think?',
            ]);
            Message::create([
                'project_id' => $project->id,
                'user_id' => $client->id,
                'body' => 'Thanks, it looks great! Just one small note about the hero image — can we make it a little brighter?',
            ]);
            Message::create([
                'project_id' => $project->id,
                'user_id' => $developer->id,
                'body' => 'Sure, I\'ll adjust the hero and push an update to staging shortly.',
            ]);

            // クライアントのお知らせ（ベルに未読が出るように）
            $client->notifications()->delete();
            $client->notifications()->createMany([
                [
                    'type' => 'task_pending_review',
                    'data' => ['task_title' => $createdTasks[0]->title, 'task_id' => $createdTasks[0]->id, 'project_id' => $project->id],
                    'read_at' => null,
                ],
                [
                    'type' => 'request_delivered',
                    'data' => ['request_title' => 'Brighter hero image on the home page', 'request_id' => $this->deliveredRequestId, 'company_id' => $company->id, 'company_name' => $company->name, 'actor_name' => $developer->name],
                    'read_at' => null,
                ],
                [
                    'type' => 'invoice_sent',
                    'data' => ['invoice_number' => $invoice->invoice_number, 'invoice_id' => $invoice->id, 'project_id' => $project->id],
                    'read_at' => null,
                ],
            ]);
        });

        $this->command?->info('Demo data ready.');
        $this->command?->info('  Developer: '.self::DEV_EMAIL.' / '.self::PASSWORD);
        $this->command?->info('  Client:    '.self::CLIENT_EMAIL.' / '.self::PASSWORD);
    }

    private ?int $deliveredRequestId = null;

    /**
     * 依頼キュー（すべての状態が一度に見えるように）と、推薦文・紹介。
     * 日時は「今」から逆算する。毎日 04:00 のリセットでも古びないように。
     */
    private function seedRequestQueue(Company $company, Project $project, User $developer, User $client): void
    {
        $company->workRequests()->delete(); // 履歴は外部キーの cascade で消える
        Testimonial::where('company_id', $company->id)->delete();
        Referral::where('company_id', $company->id)->delete();

        $make = function (array $attrs, array $events) use ($company, $project, $client) {
            $r = WorkRequest::create([
                'company_id' => $company->id,
                'project_id' => $project->id,
                'created_by' => $client->id,
                'category' => 'change',
                'priority' => 'normal',
                ...$attrs,
            ]);
            foreach ($events as [$type, $user, $body, $hoursAgo, $extra]) {
                $e = $r->events()->create([
                    'user_id' => $user?->id,
                    'type' => $type,
                    'body' => $body,
                    'internal' => in_array($type, ['note', 'time'], true),
                    'minutes' => $extra['minutes'] ?? null,
                    'meta' => $extra['meta'] ?? null,
                ]);
                $e->forceFill(['created_at' => now()->subHours($hoursAgo), 'updated_at' => now()->subHours($hoursAgo)])->save();
            }

            return $r;
        };

        $delivered = $make([
            'title' => 'Brighter hero image on the home page',
            'description' => 'The hero photo feels a bit dark on phones. Could you brighten it, or try the alternate photo from the shoot?',
            'category' => 'design',
            'status' => 'client_review',
            'video_url' => 'https://www.loom.com/share/demo-hero-walkthrough',
            'started_at' => now()->subHours(30),
            'delivered_at' => now()->subHours(2),
            'due_at' => now()->addHours(40),
            'assignee_id' => $developer->id,
            'spent_minutes' => 50,
            'delivery_note' => "Brightened the hero by about 15% and added a soft gradient so the headline stays readable.\nThe alternate photo is on staging too — switch with the toggle at the top right.",
            'delivery_url' => 'https://staging.example.com',
            'checklist' => [
                ['text' => 'Colour-correct the original photo', 'done' => true],
                ['text' => 'Prepare the alternate photo', 'done' => true],
                ['text' => 'Check contrast on mobile', 'done' => true],
            ],
        ], [
            ['created', $client, null, 34, []],
            ['started', $developer, null, 30, []],
            ['comment', $developer, 'Thanks for the video, that made it clear. Starting on this now.', 29, []],
            ['time', $developer, 'Colour correction + gradient', 6, ['minutes' => 50]],
            ['delivered', $developer, 'Brightened the hero and put the alternate photo on staging.', 2, ['meta' => ['url' => 'https://staging.example.com']]],
        ]);
        $this->deliveredRequestId = $delivered->id;

        $make([
            'title' => 'Add a wholesale inquiry form',
            'description' => 'Restaurants keep emailing us for wholesale prices. A short form (company, contact, products of interest) would help.',
            'category' => 'new_page',
            'priority' => 'high',
            'status' => 'in_progress',
            'started_at' => now()->subHours(5),
            'due_at' => now()->addHours(67),
            'assignee_id' => $developer->id,
            'estimate_hours' => 2.5,
            'spent_minutes' => 40,
            'revision_count' => 0,
            'checklist' => [
                ['text' => 'Form fields and validation', 'done' => true],
                ['text' => 'Email notification to sales', 'done' => false],
                ['text' => 'Japanese translation', 'done' => false],
            ],
        ], [
            ['created', $client, null, 50, []],
            ['started', $developer, null, 5, []],
            ['note', $developer, 'Reuse the contact form component; sales address is in the brief.', 5, []],
            ['time', $developer, 'Form + validation', 3, ['minutes' => 40]],
        ]);

        foreach ([
            ['Update the holiday opening hours', 'Closed Dec 24 – Jan 2. Please update the footer and the contact page.', 'content', 'normal'],
            ['Fix the broken link in the footer', 'The "Shipping policy" link goes to a 404.', 'bug', 'normal'],
            ['Add the new matcha set to the catalogue', 'Photos and description are in the shared folder.', 'content', 'low'],
        ] as $i => [$title, $desc, $category, $priority]) {
            $make([
                'title' => $title,
                'description' => $desc,
                'category' => $category,
                'priority' => $priority,
                'status' => 'queued',
                'position' => $i + 1,
            ], [['created', $client, null, 20 - $i, []]]);
        }

        $make([
            'title' => 'Switch the menu PDF to the spring edition',
            'category' => 'content',
            'status' => 'done',
            'started_at' => now()->subDays(9),
            'delivered_at' => now()->subDays(8),
            'completed_at' => now()->subDays(8),
            'rating' => 5,
            'spent_minutes' => 20,
            'delivery_note' => 'Replaced the PDF and updated the download button label.',
        ], [
            ['created', $client, null, 24 * 10, []],
            ['started', $developer, null, 24 * 9, []],
            ['delivered', $developer, 'Replaced the PDF.', 24 * 8 + 3, []],
            ['approved', $client, 'Perfect, thank you!', 24 * 8, ['meta' => ['rating' => 5]]],
        ]);

        Testimonial::create([
            'company_id' => $company->id,
            'project_id' => $project->id,
            'user_id' => $client->id,
            'rating' => 5,
            'body' => 'We send small changes whenever we think of them and they just get done, in order, with a note on what changed. No more chasing emails.',
            'display_name' => 'Aoi Tanaka, Sakura Trading (Demo)',
            'allow_publish' => true,
        ]);
        Referral::create([
            'company_id' => $company->id,
            'user_id' => $client->id,
            'referred_name' => 'Maple Tea House (Demo)',
            'contact_name' => 'Ken Sato',
            'contact_email' => 'ken@example.com',
            'note' => 'A café we supply. Their site is very old.',
            'status' => 'contacted',
        ]);
    }
}

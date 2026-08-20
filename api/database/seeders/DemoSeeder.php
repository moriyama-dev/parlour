<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
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
}

<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\BoardController;
use App\Http\Controllers\Api\CallController;
use App\Http\Controllers\Api\CallInboxController;
use App\Http\Controllers\Api\CallSignalController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\PushSubscriptionController;
use App\Http\Controllers\Api\RequestQueueController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\TaskApprovalController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\WorkRequestController;
use Illuminate\Support\Facades\Route;

/*
 * `route('login')` の解決先。
 *
 * bootstrap/app.php が AuthenticationException を JSON 401 にしているので通常は
 * 呼ばれないが、フレームワークのどこかがこの名前を引いたときに
 * RouteNotFoundException で 500 にならないようにするための stub。
 * routes/web.php ではなくここに置くのは、web グループだとリクエストごとに
 * sessions 行が書かれるため。
 */
Route::any('/login', fn () => response()->json(['message' => 'Unauthenticated.'], 401))
    ->name('login');

/*
 * Public routes (no auth required).
 *
 * These three are the entire unauthenticated write surface, so they are the only
 * ones an anonymous visitor can spend our CPU on. Login is throttled hardest: a
 * failed attempt costs ~200ms of bcrypt whether or not the address exists, so
 * without a limit five requests a second saturate a core. See
 * AppServiceProvider::configureRateLimiting for why the keys are what they are.
 */
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::get('/invitations/{token}', [InvitationController::class, 'show'])->middleware('throttle:invite');
Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])->middleware('throttle:invite');

/*
 * Stripe からの通知。ログインの外にあり、信用の根拠は署名だけ
 * （StripeWebhookController が検証してから中身を読む）。
 */
Route::post('/stripe/webhook', StripeWebhookController::class)->middleware('throttle:stripe-webhook');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // Companies (developer only)
    Route::middleware('role:developer')->group(function () {
        Route::get('/companies', [CompanyController::class, 'index']);
        Route::post('/companies', [CompanyController::class, 'store']);
        Route::get('/companies/{company}', [CompanyController::class, 'show']);
        Route::put('/companies/{company}', [CompanyController::class, 'update']);
        Route::post('/companies/{company}/users', [CompanyController::class, 'addUser']);
        Route::delete('/companies/{company}/users/{user}', [CompanyController::class, 'removeUser']);
        Route::get('/companies/{company}/invitations', [InvitationController::class, 'index']);
        Route::post('/companies/{company}/invitations', [InvitationController::class, 'store']);
    });

    // Invitations (developer only)
    Route::get('/invitations', [InvitationController::class, 'globalIndex']);
    Route::delete('/invitations/{invitation}', [InvitationController::class, 'destroy']);

    /*
     * 自分が案件を作れる会社の一覧。/companies と違って顧客も引ける
     * （返るのは自分の所属会社だけ）。
     */
    Route::get('/me/companies', [CompanyController::class, 'mine']);

    /*
     * 「料金」ページ。案件をまたいだ請求書・領収書の読み取り専用の窓口。
     * 顧客に返るのは自社宛かつ送付済みのものだけ（BillingController::visible）。
     */
    Route::get('/billing/invoices', [BillingController::class, 'invoices']);
    Route::get('/billing/invoices/{invoice}', [BillingController::class, 'invoice']);

    /*
     * サブスク（会社ごとの定期契約。課金は Stripe が行う）。
     * 一覧・詳細は顧客も読める（自社のものだけ。Subscription::visibleTo）。
     * checkout は顧客本人だけ（カードを入れる人）、作成・変更・解約は開発者だけ。
     */
    Route::get('/billing/subscriptions', [SubscriptionController::class, 'index']);
    Route::get('/billing/subscriptions/{subscription}', [SubscriptionController::class, 'show']);
    Route::middleware('throttle:billing-redirect')->group(function () {
        Route::post('/billing/subscriptions/{subscription}/checkout', [SubscriptionController::class, 'checkout']);
        Route::post('/billing/portal', [SubscriptionController::class, 'portal']);
    });
    Route::middleware('role:developer')->group(function () {
        Route::post('/billing/subscriptions', [SubscriptionController::class, 'store']);
        Route::put('/billing/subscriptions/{subscription}', [SubscriptionController::class, 'update']);
        Route::post('/billing/subscriptions/{subscription}/cancel', [SubscriptionController::class, 'cancel']);
        Route::post('/billing/subscriptions/{subscription}/resume', [SubscriptionController::class, 'resume']);
        Route::post('/billing/subscriptions/{subscription}/refresh', [SubscriptionController::class, 'refresh']);
    });

    // Projects (index is scoped per role inside the controller)
    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store']);

    /*
     * Everything below hangs off a single project. Two guarantees are enforced
     * here rather than repeated in ten controllers:
     *
     *   project.access  — the caller may see this project at all (a client only
     *                     reaches projects belonging to their own company).
     *   scopeBindings() — {task} and {invoice} must belong to {project}, so an
     *                     id from someone else's project resolves to a 404.
     */
    Route::middleware('project.access')->scopeBindings()->group(function () {
        /*
         * Studio-only writes.
         *
         * These used to rely on an `if ($user->role !== 'developer')` line at the
         * top of each controller method, and four of them were missing it — a
         * client could mark their own invoice paid, rewrite a draft invoice's
         * amounts, and change a project's status. Role is a property of the route,
         * not of the method body, so it is enforced here where forgetting it is
         * not possible. The controllers keep their own checks as a second layer.
         *
         * Reads stay outside this group: a client must still see their project,
         * tasks, invoices and messages, and must still approve/reject tasks.
         */
        Route::middleware('role:developer')->group(function () {
            Route::put('/projects/{project}', [ProjectController::class, 'update']);
            Route::patch('/projects/{project}/status', [ProjectController::class, 'updateStatus']);

            Route::post('/projects/{project}/tasks', [TaskController::class, 'store']);
            Route::put('/projects/{project}/tasks/{task}', [TaskController::class, 'update']);
            Route::patch('/projects/{project}/tasks/{task}/deploy', [TaskController::class, 'markDeployed']);

            Route::post('/projects/{project}/invoices', [InvoiceController::class, 'store']);
            Route::put('/projects/{project}/invoices/{invoice}', [InvoiceController::class, 'update']);
            Route::patch('/projects/{project}/invoices/{invoice}/send', [InvoiceController::class, 'send']);
            Route::patch('/projects/{project}/invoices/{invoice}/paid', [InvoiceController::class, 'markPaid']);
        });

        Route::get('/projects/{project}', [ProjectController::class, 'show']);

        // Tasks
        Route::get('/projects/{project}/tasks', [TaskController::class, 'index']);
        Route::get('/projects/{project}/tasks/{task}', [TaskController::class, 'show']);
        Route::post('/projects/{project}/tasks/{task}/approve', [TaskController::class, 'approve']);
        Route::post('/projects/{project}/tasks/{task}/reject', [TaskController::class, 'reject']);

        // Task Approvals
        Route::get('/projects/{project}/tasks/{task}/approvals', [TaskApprovalController::class, 'index']);

        // Messages
        Route::get('/projects/{project}/messages', [MessageController::class, 'index']);
        Route::post('/projects/{project}/messages', [MessageController::class, 'store']);

        /*
         * Video calls. The heavy lifting is peer-to-peer in the browser; these
         * routes only run the room. `heartbeat` is the hot one — every browser in
         * a call hits it once a second, and it deliberately answers both "who is
         * here" and "any signals for me" in a single round trip.
         */
        Route::get('/projects/{project}/calls', [CallController::class, 'index']);
        Route::post('/projects/{project}/calls', [CallController::class, 'store'])
            ->middleware('throttle:calls-create');
        Route::get('/projects/{project}/calls/invitees', [CallController::class, 'invitees']);
        Route::get('/projects/{project}/calls/{call}', [CallController::class, 'show']);
        Route::post('/projects/{project}/calls/{call}/join', [CallController::class, 'join']);
        Route::post('/projects/{project}/calls/{call}/heartbeat', [CallController::class, 'heartbeat'])
            ->middleware('throttle:call-poll');
        Route::post('/projects/{project}/calls/{call}/leave', [CallController::class, 'leave']);
        Route::post('/projects/{project}/calls/{call}/decline', [CallController::class, 'decline']);
        Route::post('/projects/{project}/calls/{call}/end', [CallController::class, 'end']);
        Route::post('/projects/{project}/calls/{call}/signals', [CallSignalController::class, 'store'])
            ->middleware('throttle:signals');

        // Invoices (reads only — every write is in the developer group above)
        Route::get('/projects/{project}/invoices', [InvoiceController::class, 'index']);
        Route::get('/projects/{project}/invoices/{invoice}', [InvoiceController::class, 'show']);
    });

    // Clients (developer only — the client directory holds every client's contact details)
    Route::middleware('role:developer')->group(function () {
        Route::get('/clients', [ClientController::class, 'index']);
        Route::put('/clients/{user}', [ClientController::class, 'update']);
        Route::delete('/clients/{user}', [ClientController::class, 'destroy']);
    });

    /*
     * Call inbox — not nested under a project because every logged-in browser
     * polls it, whatever page they are on, to find out they are being called.
     */
    Route::get('/calls/ringing', [CallInboxController::class, 'ringing'])
        ->middleware('throttle:ringing');
    Route::get('/calls/ice', [CallInboxController::class, 'ice']);

    /*
     * Whiteboards (the "Image" menu). Not nested under a project: a board is
     * personal by default and only optionally shared with one project's client.
     * Visibility is decided inside the controller (Board::visibleTo).
     */
    Route::get('/boards', [BoardController::class, 'index']);
    Route::post('/boards', [BoardController::class, 'store']);
    Route::get('/boards/{board}', [BoardController::class, 'show']);
    Route::put('/boards/{board}', [BoardController::class, 'update']);
    Route::delete('/boards/{board}', [BoardController::class, 'destroy']);
    Route::post('/boards/{board}/duplicate', [BoardController::class, 'duplicate']);

    /*
     * 端末への通知（Web Push）。購読は端末ごと、受け取る分類のオン・オフは利用者ごと。
     * 書き込みは自分の行にしか届かない（コントローラが $request->user() 起点で引く）。
     */
    Route::get('/push', [PushSubscriptionController::class, 'show']);
    Route::middleware('throttle:push')->group(function () {
        Route::post('/push/subscriptions', [PushSubscriptionController::class, 'store']);
        Route::delete('/push/subscriptions', [PushSubscriptionController::class, 'destroy']);
        Route::put('/push/preferences', [PushSubscriptionController::class, 'updatePreferences']);
        Route::post('/push/test', [PushSubscriptionController::class, 'test']);
    });

    /*
     * 依頼キュー（Conte Studios の VIP 方式）。顧客が依頼を積み、こちらが会社ごとの
     * 同時進行数の枠内で上から1件ずつ消化する。案件の下ではなく会社の下にぶら下がる
     * （案件は任意で紐づけるだけ）ので project.access の外に置き、会社の照合は
     * コントローラが行う（Company::isVisibleTo）。他社の依頼は 404。
     *
     * 着手・納品・保留・作業時間・枠の設定は開発者だけ。承認と修正依頼は顧客だけ
     * （コントローラ側で役割を見る。開発者が自分の納品を承認できないように）。
     */
    Route::get('/requests', [WorkRequestController::class, 'index']);
    Route::get('/requests/{workRequest}', [WorkRequestController::class, 'show']);
    Route::get('/request-queues/mine', [RequestQueueController::class, 'mine']);
    Route::get('/request-queues/{company}', [RequestQueueController::class, 'show']);
    Route::middleware('throttle:work-requests')->group(function () {
        Route::post('/requests', [WorkRequestController::class, 'store']);
        Route::put('/requests/{workRequest}', [WorkRequestController::class, 'update']);
        Route::post('/requests/reorder', [WorkRequestController::class, 'reorder']);
        Route::post('/requests/{workRequest}/comments', [WorkRequestController::class, 'comment']);
        Route::post('/requests/{workRequest}/approve', [WorkRequestController::class, 'approve']);
        Route::post('/requests/{workRequest}/revision', [WorkRequestController::class, 'revision']);
        Route::post('/requests/{workRequest}/cancel', [WorkRequestController::class, 'cancel']);
        Route::post('/request-queues/{company}/pause', [RequestQueueController::class, 'pause']);
        Route::post('/request-queues/{company}/resume', [RequestQueueController::class, 'resume']);

        Route::middleware('role:developer')->group(function () {
            Route::post('/requests/{workRequest}/start', [WorkRequestController::class, 'start']);
            Route::post('/requests/{workRequest}/deliver', [WorkRequestController::class, 'deliver']);
            Route::post('/requests/{workRequest}/hold', [WorkRequestController::class, 'hold']);
            Route::post('/requests/{workRequest}/time', [WorkRequestController::class, 'logTime']);
            Route::put('/request-queues/{company}', [RequestQueueController::class, 'update']);
            Route::post('/request-queues/{company}/next', [RequestQueueController::class, 'next']);
        });
    });
    Route::get('/request-queues', [RequestQueueController::class, 'index'])->middleware('role:developer');

    /*
     * 推薦文と紹介。書くのは顧客（自社名義だけ）、読むのは開発者（顧客は自社のものだけ）。
     */
    Route::get('/testimonials', [FeedbackController::class, 'testimonials']);
    Route::get('/referrals', [FeedbackController::class, 'referrals']);
    Route::middleware('throttle:work-requests')->group(function () {
        Route::post('/testimonials', [FeedbackController::class, 'storeTestimonial']);
        Route::post('/referrals', [FeedbackController::class, 'storeReferral']);
        Route::patch('/referrals/{referral}', [FeedbackController::class, 'updateReferral'])->middleware('role:developer');
    });

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
});

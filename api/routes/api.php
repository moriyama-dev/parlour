<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\TaskApprovalController;
use App\Http\Controllers\Api\TaskController;
use Illuminate\Support\Facades\Route;

// Public routes (no auth required)
Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/invitations/{token}', [InvitationController::class, 'show']);
Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept']);

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

    // Projects
    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store']);
    Route::get('/projects/{project}', [ProjectController::class, 'show']);
    Route::put('/projects/{project}', [ProjectController::class, 'update']);
    Route::patch('/projects/{project}/status', [ProjectController::class, 'updateStatus']);

    // Tasks
    Route::get('/projects/{project}/tasks', [TaskController::class, 'index']);
    Route::post('/projects/{project}/tasks', [TaskController::class, 'store']);
    Route::get('/projects/{project}/tasks/{task}', [TaskController::class, 'show']);
    Route::put('/projects/{project}/tasks/{task}', [TaskController::class, 'update']);
    Route::post('/projects/{project}/tasks/{task}/approve', [TaskController::class, 'approve']);
    Route::post('/projects/{project}/tasks/{task}/reject', [TaskController::class, 'reject']);
    Route::patch('/projects/{project}/tasks/{task}/deploy', [TaskController::class, 'markDeployed']);

    // Task Approvals
    Route::get('/projects/{project}/tasks/{task}/approvals', [TaskApprovalController::class, 'index']);

    // Messages
    Route::get('/projects/{project}/messages', [MessageController::class, 'index']);
    Route::post('/projects/{project}/messages', [MessageController::class, 'store']);

    // Invoices
    Route::get('/projects/{project}/invoices', [InvoiceController::class, 'index']);
    Route::post('/projects/{project}/invoices', [InvoiceController::class, 'store']);
    Route::get('/projects/{project}/invoices/{invoice}', [InvoiceController::class, 'show']);
    Route::put('/projects/{project}/invoices/{invoice}', [InvoiceController::class, 'update']);
    Route::patch('/projects/{project}/invoices/{invoice}/send', [InvoiceController::class, 'send']);
    Route::patch('/projects/{project}/invoices/{invoice}/paid', [InvoiceController::class, 'markPaid']);

    // Clients
    Route::get('/clients', [ClientController::class, 'index']);
    Route::put('/clients/{user}', [ClientController::class, 'update']);
    Route::delete('/clients/{user}', [ClientController::class, 'destroy']);

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
});

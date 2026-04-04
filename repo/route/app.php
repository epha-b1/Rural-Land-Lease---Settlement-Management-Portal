<?php
// Application route definitions
use think\facade\Route;

// === Slice 1: Foundation ===
Route::get('health', 'Health/index');
Route::get('/', function () {
    return redirect('/static/index.html');
});

// === Slice 2: Auth and Identity ===
Route::post('auth/register', 'Auth/register');
Route::post('auth/login', 'Auth/login');
Route::post('auth/logout', 'Auth/logout')->middleware('authCheck');
Route::get('auth/me', 'Auth/me')->middleware('authCheck');
Route::post('auth/mfa/enroll', 'Auth/mfaEnroll')->middleware('authCheck', 'system_admin');
Route::post('auth/mfa/verify', 'Auth/mfaVerify')->middleware('authCheck', 'system_admin');

// === Slice 3: Profiles, Verification, Scope ===

// Entity profiles: specific routes BEFORE general list (order matters)
Route::post('entities/:id/merge', 'Entity/merge')->middleware('authCheck')->pattern(['id' => '\d+']);
Route::get('entities/:id', 'Entity/read')->middleware('authCheck')->pattern(['id' => '\d+']);
Route::patch('entities/:id', 'Entity/update')->middleware('authCheck')->pattern(['id' => '\d+']);
Route::get('entities', 'Entity/index')->middleware('authCheck')->completeMatch(true);
Route::post('entities', 'Entity/create')->middleware('authCheck')->completeMatch(true);

// Verification - specific routes first
Route::post('admin/verifications/:id/approve', 'Verification/approve')->middleware('authCheck', 'system_admin')->pattern(['id' => '\d+']);
Route::post('admin/verifications/:id/reject', 'Verification/reject')->middleware('authCheck', 'system_admin')->pattern(['id' => '\d+']);
Route::post('verifications', 'Verification/submit')->middleware('authCheck')->completeMatch(true);
Route::get('verifications', 'Verification/index')->middleware('authCheck', 'system_admin')->completeMatch(true);

// === Slice 4: Contracts and Invoices ===
Route::get('contracts/:id', 'Contract/read')->middleware('authCheck')->pattern(['id' => '\d+']);
Route::get('contracts', 'Contract/index')->middleware('authCheck')->completeMatch(true);
Route::post('contracts', 'Contract/create')->middleware('authCheck')->completeMatch(true);

Route::get('invoices/:id/receipt', 'Payment/receipt')->middleware('authCheck')->pattern(['id' => '\d+']);
Route::get('invoices/:id', 'Invoice/read')->middleware('authCheck')->pattern(['id' => '\d+']);
Route::get('invoices', 'Invoice/index')->middleware('authCheck')->completeMatch(true);

// === Slice 5: Payments, Refunds, Exports ===
Route::post('payments', 'Payment/create')->middleware('authCheck')->completeMatch(true);
Route::post('refunds', 'Payment/refund')->middleware('authCheck')->completeMatch(true);
Route::get('exports/ledger', 'Payment/ledger')->middleware('authCheck');
Route::get('exports/reconciliation', 'Payment/reconciliation')->middleware('authCheck');

// === Slice 6: Messaging and Risk ===
Route::get('conversations/:id/messages', 'Message/messages')->middleware('authCheck')->pattern(['id' => '\d+']);
Route::get('conversations', 'Message/conversations')->middleware('authCheck')->completeMatch(true);
Route::post('conversations', 'Message/createConversation')->middleware('authCheck')->completeMatch(true);
Route::post('messages', 'Message/send')->middleware('authCheck')->completeMatch(true);
Route::patch('messages/:id/recall', 'Message/recall')->middleware('authCheck')->pattern(['id' => '\d+']);
Route::post('messages/:id/report', 'Message/report')->middleware('authCheck')->pattern(['id' => '\d+']);

// Admin risk keyword management
Route::get('admin/risk-keywords', 'Message/riskKeywords')->middleware('authCheck', 'system_admin');
Route::post('admin/risk-keywords', 'Message/createRiskKeyword')->middleware('authCheck', 'system_admin')->completeMatch(true);
Route::patch('admin/risk-keywords/:id', 'Message/updateRiskKeyword')->middleware('authCheck', 'system_admin')->pattern(['id' => '\d+']);
Route::delete('admin/risk-keywords/:id', 'Message/deleteRiskKeyword')->middleware('authCheck', 'system_admin')->pattern(['id' => '\d+']);

// === Slice 7: Audit ===
Route::get('audit-logs', 'Audit/index')->middleware('authCheck', 'system_admin')->completeMatch(true);

// === Slice 8: Admin, Jobs, Config, API Docs ===
Route::get('api/docs', 'Admin/apiDocs');
Route::get('admin/jobs', 'Admin/listJobs')->middleware('authCheck', 'system_admin');
Route::post('admin/jobs/run', 'Admin/runJobs')->middleware('authCheck', 'system_admin');
Route::get('admin/config', 'Admin/getConfig')->middleware('authCheck', 'system_admin');
Route::patch('admin/config/:key', 'Admin/updateConfig')->middleware('authCheck', 'system_admin');

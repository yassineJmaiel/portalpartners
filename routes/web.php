<?php

use App\Http\Controllers\AuthController as A;
use App\Http\Controllers\ClaimsController as C;
use App\Http\Controllers\CopilotController as Co;
use App\Http\Controllers\EligibilityController as E;
use App\Http\Controllers\FollowUpController as F;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\IntakeController as I;
use App\Http\Controllers\NotesController as N;
use App\Http\Controllers\OrganisationController as O;
use App\Http\Controllers\PaymentsController as Pay;
use App\Http\Controllers\PreauthController as P;
use App\Http\Controllers\ShellController as S;
use App\Http\Middleware\PortalSession;
use Illuminate\Support\Facades\Route;

/* public doors */
Route::get('/verify/{ref}', [S::class, 'verify']);
Route::get('/health', [S::class, 'health']);

/* signing in */
Route::get('/login', [A::class, 'show'])->name('login');
Route::post('/login', [A::class, 'login']);
Route::post('/login/code', [A::class, 'verify']);
Route::post('/login/resend', [A::class, 'resend']);
Route::get('/forgot', [A::class, 'forgotForm']);
Route::post('/forgot', [A::class, 'forgot']);
Route::post('/logout', [A::class, 'logout']);
Route::post('/language', [A::class, 'language']);
Route::get('/accept/{token}', [A::class, 'acceptForm']);
Route::post('/accept/{token}', [A::class, 'accept']);

Route::middleware(PortalSession::class)->group(function () {
    Route::get('/', [HomeController::class, 'index']);
    Route::post('/me/provider', [S::class, 'setProvider']);
    Route::get('/notifications', [S::class, 'notifications']);
    Route::post('/notifications/read', [S::class, 'notificationsRead']);
    Route::get('/live', [S::class, 'live']);
    Route::get('/search', [S::class, 'search']);
    Route::get('/icd10', [S::class, 'icd10']);
    Route::get('/files/{ref}', [S::class, 'file']);
    Route::post('/feedback', [S::class, 'feedback']);

    Route::get('/eligibility', [E::class, 'index']);
    Route::post('/eligibility', [E::class, 'check']);
    Route::get('/eligibility/{id}/certificate', [E::class, 'certificate']);
    Route::post('/check-ins', [E::class, 'checkIn']);
    Route::post('/check-ins/{id}/consent', [E::class, 'consent']);
    Route::post('/check-ins/{id}/consent/verify', [E::class, 'consentVerify']);

    Route::get('/claims', [C::class, 'index']);
    Route::get('/claims/export', [C::class, 'export']);
    Route::get('/claims/new', [C::class, 'wizard']);
    Route::post('/claims/new', [C::class, 'wizardStep']);
    Route::get('/claims/bulk', [C::class, 'bulk']);
    Route::get('/claims/bulk/template', [C::class, 'template']);
    Route::post('/claims/bulk', [C::class, 'bulkRead']);
    Route::post('/drafts/{id}/delete', [C::class, 'deleteDraft']);
    Route::get('/claims/{id}', [C::class, 'show']);
    Route::get('/claims/{id}/received', [C::class, 'received']);
    Route::get('/claims/{id}/acknowledgement', [C::class, 'acknowledgement']);
    Route::post('/claims/{id}/reply', [C::class, 'reply']);
    Route::post('/claims/{id}/withdraw', [C::class, 'withdraw']);

    Route::get('/preauth', [P::class, 'index']);
    Route::get('/preauth/new', [P::class, 'wizard']);
    Route::post('/preauth/new', [P::class, 'wizardStep']);
    Route::get('/preauth/{id}', [P::class, 'show']);
    Route::get('/preauth/{id}/received', [P::class, 'received']);
    Route::post('/preauth/{id}/reply', [P::class, 'reply']);
    Route::post('/preauth/{id}/gop-request', [P::class, 'gopRequest']);
    Route::post('/preauth/{id}/to-claim', [P::class, 'toClaim']);

    Route::get('/payments', [Pay::class, 'index']);
    Route::post('/payments/reconcile', [Pay::class, 'reconcile']);
    Route::get('/payments/export/{format}/{id?}', [Pay::class, 'export'])->whereIn('format', ['csv', 'xlsx']);
    Route::get('/payments/{id}', [Pay::class, 'show']);
    Route::get('/payments/{id}/remittance', [Pay::class, 'remittance']);
    Route::get('/documents/{kind}/{id}', [Pay::class, 'document']);

    Route::get('/appeals', [F::class, 'appeals']);
    Route::post('/appeals', [F::class, 'appeal']);
    Route::get('/messages', [F::class, 'messages']);
    Route::post('/messages', [F::class, 'sendMessage']);
    Route::get('/insights', [F::class, 'insights']);

    Route::post('/notes/{type}/{id}', [N::class, 'store']);
    Route::post('/handoffs/{id}/done', [N::class, 'done']);

    Route::get('/intake', [I::class, 'index']);
    Route::post('/intake', [I::class, 'upload']);
    Route::get('/intake/{id}', [I::class, 'show']);
    Route::get('/intake/{id}/status', [I::class, 'status']);
    Route::post('/intake/docs/{id}', [I::class, 'review']);
    Route::post('/intake/{id}/file', [I::class, 'file']);

    Route::get('/copilot', [Co::class, 'index']);
    Route::post('/copilot', [Co::class, 'ask']);
    Route::post('/copilot/clear', [Co::class, 'clear']);

    Route::get('/team', [O::class, 'team']);
    Route::post('/team', [O::class, 'invite']);
    Route::post('/team/{id}', [O::class, 'updateMember']);
    Route::post('/team/{id}/reset', [O::class, 'resetLink']);
    Route::post('/feedback/{id}', [O::class, 'feedbackStatus']);

    Route::get('/settings', [O::class, 'settings']);
    Route::post('/settings/bank-change', [O::class, 'bankChange']);
    Route::post('/settings/notify', [O::class, 'notifyPref']);
    Route::post('/settings/security', [O::class, 'security']);
    Route::post('/settings/endpoints', [O::class, 'addEndpoint']);
    Route::post('/settings/endpoints/{id}/delete', [O::class, 'removeEndpoint']);
    Route::post('/settings/endpoints/{id}/test', [O::class, 'testEndpoint']);
});

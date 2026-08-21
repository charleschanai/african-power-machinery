<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CardController;
use App\Http\Controllers\Api\CardImageController;
use App\Http\Controllers\Api\CardInvitationController;
use App\Http\Controllers\Api\CardTemplateController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EntryLogController;
use App\Http\Controllers\Api\GuestController;
use App\Http\Controllers\Api\InvitationCampaignController;
use App\Http\Controllers\Api\PasswordController;
use App\Http\Controllers\Api\PublicCardController;
use App\Http\Controllers\Api\PublicWhatsAppController;
use App\Http\Controllers\Api\TwoFactorController;
use App\Http\Controllers\Api\WhatsAppAdminController;
use App\Http\Controllers\Api\WeeklyReminderController;
use App\Http\Controllers\Api\WhatsAppController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::post('/password/forgot', [PasswordController::class, 'forgot'])->middleware('throttle:5,1');
    Route::post('/password/reset', [PasswordController::class, 'reset'])->middleware('throttle:5,1');

    Route::get('/public/card/{qrToken}', [PublicCardController::class, 'show'])
        ->middleware('throttle:public');
    Route::get('/public/whatsapp-number', [PublicWhatsAppController::class, 'number'])
        ->middleware('throttle:public');

    Route::post('/auth/two-factor/verify', [TwoFactorController::class, 'verify'])
        ->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        Route::get('/auth/two-factor', [TwoFactorController::class, 'status']);
        Route::post('/auth/two-factor/enable', [TwoFactorController::class, 'enable']);
        Route::post('/auth/two-factor/confirm', [TwoFactorController::class, 'confirm']);
        Route::post('/auth/two-factor/disable', [TwoFactorController::class, 'disable']);
        Route::post('/auth/two-factor/regenerate', [TwoFactorController::class, 'regenerateRecoveryCodes']);

        Route::get('/guests/search', [GuestController::class, 'search']);
        Route::get('/guests/export', [GuestController::class, 'export']);
        Route::get('/guests', [GuestController::class, 'index']);
        Route::get('/guests/{guest}', [GuestController::class, 'show']);
        Route::post('/guests', [GuestController::class, 'store'])->middleware('role:admin');
        Route::put('/guests/{guest}', [GuestController::class, 'update'])->middleware('role:admin');
        Route::delete('/guests/{guest}', [GuestController::class, 'destroy'])->middleware('role:admin');

        Route::get('/card-templates', [CardTemplateController::class, 'index']);
        Route::post('/card-templates', [CardTemplateController::class, 'store'])->middleware('role:admin');
        Route::get('/card-templates/{cardTemplate}', [CardTemplateController::class, 'show']);
        Route::put('/card-templates/{cardTemplate}', [CardTemplateController::class, 'update'])->middleware('role:admin');
        Route::post('/card-templates/{cardTemplate}/toggle-status', [CardTemplateController::class, 'toggleStatus'])->middleware('role:admin');
        Route::delete('/card-templates/{cardTemplate}', [CardTemplateController::class, 'destroy'])->middleware('role:admin');

        Route::get('/cards/export', [CardController::class, 'export']);
        Route::get('/cards/export-links', [CardController::class, 'exportLinks'])->middleware('role:admin');
        Route::get('/cards/import-template', [CardController::class, 'importTemplate']);
        Route::post('/cards/import', [CardController::class, 'import'])->middleware('role:admin');
        Route::get('/cards', [CardController::class, 'index']);
        Route::post('/cards', [CardController::class, 'store'])->middleware('role:admin');
        Route::post('/cards/send-invitations', [CardInvitationController::class, 'sendMany'])->middleware('role:admin');
        Route::get('/cards/{card}', [CardController::class, 'show'])->middleware('throttle:120,1');
        Route::post('/cards/{card}/send-invitation', [CardInvitationController::class, 'send'])->middleware('role:admin');
        Route::post('/cards/{card}/send-email', [CardController::class, 'sendEmail'])->middleware('role:admin');
        Route::post('/cards/send-emails', [CardController::class, 'sendEmails'])->middleware('role:admin');
        Route::get('/cards/{card}/share-data', [CardImageController::class, 'show'])->middleware('throttle:120,1');
        Route::get('/cards/{card}/composite/{side?}', [CardImageController::class, 'composite'])->middleware('throttle:30,1');
        Route::put('/cards/{card}', [CardController::class, 'update'])->middleware('role:admin');
        Route::post('/cards/verify', [CardController::class, 'verify'])->middleware('throttle:60,1');
        Route::post('/cards/confirm-entry', [CardController::class, 'confirmEntry'])->middleware('throttle:30,1');
        Route::post('/cards/{card}/cancel', [CardController::class, 'cancel'])->middleware('role:admin');
        Route::post('/cards/{card}/send-sms', [CardController::class, 'sendSms'])->middleware('role:admin');
        Route::post('/cards/send-sms-bulk', [CardController::class, 'sendSmsBulk'])->middleware('role:admin');
        Route::delete('/cards/{card}', [CardController::class, 'destroy'])->middleware('role:admin');

        Route::get('/entry-logs/export', [EntryLogController::class, 'export']);
        Route::get('/entry-logs', [EntryLogController::class, 'index']);
        Route::delete('/entry-logs/{entryLog}', [EntryLogController::class, 'destroy'])->middleware('role:admin');

        Route::get('/dashboard/statistics', [DashboardController::class, 'statistics'])->middleware('role:admin');

        Route::get('/invitation-campaigns', [InvitationCampaignController::class, 'report'])->middleware('role:admin');
        Route::get('/invitation-campaigns/preview', [InvitationCampaignController::class, 'preview'])->middleware('role:admin');
        Route::get('/invitation-campaigns/sends', [InvitationCampaignController::class, 'sends'])->middleware('role:admin');
        Route::post('/invitation-campaigns/send-all', [InvitationCampaignController::class, 'dispatchAll'])->middleware('role:admin');
        Route::post('/invitation-campaigns/send-sms-only', [InvitationCampaignController::class, 'sendSmsOnly'])->middleware('role:admin');
        Route::post('/invitation-campaigns/send-sms-scheduled', [InvitationCampaignController::class, 'sendSmsScheduled'])->middleware('role:admin');
        Route::post('/invitation-campaigns/retry-failed', [InvitationCampaignController::class, 'retryFailed'])->middleware('role:admin');

        Route::post('/whatsapp/send-card/{card}', [WhatsAppController::class, 'sendCard'])->middleware('role:admin');

        Route::get('/whatsapp/status', [WhatsAppAdminController::class, 'status'])->middleware('role:admin');
        Route::get('/whatsapp/settings', [WhatsAppAdminController::class, 'settings'])->middleware('role:admin');
        Route::put('/whatsapp/settings', [WhatsAppAdminController::class, 'updateSettings'])->middleware('role:admin');
        Route::get('/whatsapp/event-details', [WhatsAppAdminController::class, 'eventDetails'])->middleware('role:admin');
        Route::put('/whatsapp/event-details', [WhatsAppAdminController::class, 'updateEventDetails'])->middleware('role:admin');

        Route::get('/whatsapp/faqs', [WhatsAppAdminController::class, 'faqs'])->middleware('role:admin');
        Route::post('/whatsapp/faqs', [WhatsAppAdminController::class, 'storeFaq'])->middleware('role:admin');
        Route::put('/whatsapp/faqs/{faq}', [WhatsAppAdminController::class, 'updateFaq'])->middleware('role:admin');
        Route::post('/whatsapp/faqs/{faq}/toggle-status', [WhatsAppAdminController::class, 'toggleFaq'])->middleware('role:admin');
        Route::delete('/whatsapp/faqs/{faq}', [WhatsAppAdminController::class, 'destroyFaq'])->middleware('role:admin');

        Route::get('/whatsapp/conversations', [WhatsAppAdminController::class, 'conversations'])->middleware('role:admin');
        Route::get('/whatsapp/conversations/{conversation}', [WhatsAppAdminController::class, 'showConversation'])->middleware('role:admin');
        Route::post('/whatsapp/conversations/{conversation}/reply', [WhatsAppAdminController::class, 'replyConversation'])->middleware('role:admin');
        Route::post('/whatsapp/conversations/{conversation}/take-over', [WhatsAppAdminController::class, 'takeOverConversation'])->middleware('role:admin');
        Route::post('/whatsapp/conversations/{conversation}/release', [WhatsAppAdminController::class, 'releaseConversation'])->middleware('role:admin');
        Route::post('/whatsapp/conversations/{conversation}/block', [WhatsAppAdminController::class, 'blockConversation'])->middleware('role:admin');
        Route::post('/whatsapp/conversations/{conversation}/unblock', [WhatsAppAdminController::class, 'unblockConversation'])->middleware('role:admin');

        Route::get('/weekly-reminders', [WeeklyReminderController::class, 'history'])->middleware('role:admin');
        Route::get('/weekly-reminders/weeks', [WeeklyReminderController::class, 'weeks'])->middleware('role:admin');
        Route::get('/weekly-reminders/{year}/{weekNumber}', [WeeklyReminderController::class, 'weekSummary'])->middleware('role:admin');
        Route::post('/weekly-reminders/send-now', [WeeklyReminderController::class, 'sendNow'])->middleware('role:admin');
    });
});

<?php

use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('app');
});

Route::get('/login', function () {
    return view('app');
})->name('login');

Route::get('/whatsapp/webhook', [WhatsAppWebhookController::class, 'verify']);
Route::post('/whatsapp/webhook', [WhatsAppWebhookController::class, 'receive']);

Route::get('/{any}', function () {
    return view('app');
})->where('any', '^(?!api/|build/|storage/).*');

<?php

use App\Http\Controllers\WhatsAppWebhookController;
use App\Http\Middleware\VerifyWhatsAppSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

/*
 * The WhatsApp webhook. The GET is Meta's subscription handshake and authenticates
 * with the verify token; the POST is signed with the app secret and is what the
 * middleware checks. Rate limited because the URL is public and unauthenticated.
 */
Route::prefix('whatsapp')->group(function (): void {
    Route::get('webhook', [WhatsAppWebhookController::class, 'verify']);

    Route::post('webhook', [WhatsAppWebhookController::class, 'handle'])
        ->middleware([VerifyWhatsAppSignature::class, 'throttle:120,1']);
});

<?php

use App\Http\Controllers\RecallWebhookController;
use App\Http\Middleware\LimitRecallWebhookBody;
use Illuminate\Support\Facades\Route;

Route::post('/recall/webhooks', RecallWebhookController::class)
    ->middleware(LimitRecallWebhookBody::class);

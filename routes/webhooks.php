<?php

use Illuminate\Support\Facades\Route;
use JonasJohn\TzPayments\Http\Controllers\WebhookController;

Route::match(['get', 'post'], '{gateway}', WebhookController::class)->name('tz-payments.webhook');

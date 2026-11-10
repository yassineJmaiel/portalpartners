<?php

use App\Http\Controllers\ShellController;
use Illuminate\Support\Facades\Route;

/* signed by the CRM, so outside sessions and CSRF */
Route::post('/webhooks/crm', [ShellController::class, 'webhook']);

<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WithdrawalController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\TelegramController;

// Telegram Webhook
Route::post('/webhook', [TelegramController::class, 'handle']);

// Test route (GET) - للاختبار فقط
Route::get('/webhook', [TelegramController::class, 'test']);

Route::get('/', function () {
    return redirect()->route('admin.withdrawals');
});

// Login Routes - use 'login' name for auth redirect
Route::get('login', [AuthController::class, 'showLoginForm'])->name('login');
Route::post('login', [AuthController::class, 'login'])->name('login');
Route::post('logout', [AuthController::class, 'logout'])->name('logout');

// Admin Routes - Protected
Route::prefix('admin')->middleware('auth')->name('admin.')->group(function () {
    Route::get('withdrawals', [WithdrawalController::class, 'index'])->name('withdrawals');
    Route::post('withdrawals/update/{id}', [WithdrawalController::class, 'updateStatus'])->name('withdrawals.update');
    Route::delete('withdrawals/{id}', [WithdrawalController::class, 'destroy'])->name('withdrawals.destroy');

    Route::get('settings', [SettingsController::class, 'index'])->name('settings');
    Route::post('settings', [SettingsController::class, 'update'])->name('settings.update');

    // API Routes
    Route::get('withdrawals/api', [WithdrawalController::class, 'index'])->name('withdrawals.api');
    Route::post('withdrawals', [WithdrawalController::class, 'store'])->name('withdrawals.store');
    Route::get('withdrawals/{id}', [WithdrawalController::class, 'show'])->name('withdrawals.show');
    Route::get('stats', [WithdrawalController::class, 'stats'])->name('stats');
    Route::get('settings/api', [SettingsController::class, 'get'])->name('settings.get');
});

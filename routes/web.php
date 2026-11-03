<?php

use App\Http\Controllers\WalkReportPdfController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Volt::route('dashboard', 'dashboard')
    ->middleware(['auth', 'verified', 'approved'])
    ->name('dashboard');

Route::middleware(['auth', 'approved'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});

Route::middleware(['auth', 'verified', 'approved'])->prefix('admin')->name('admin.')->group(function () {
    Volt::route('access', 'admin.access')->middleware('role:super_admin')->name('access');
    Volt::route('users', 'admin.users')->middleware('permission:users.approve|users.manage|roles.manage')->name('users');
});

Route::middleware(['auth', 'verified', 'approved'])->group(function () {
    Volt::route('walks', 'walks.index')->name('walks.index');
    Volt::route('walks/create', 'walks.create')->name('walks.create');
    Volt::route('walks/{walk}', 'walks.show')->name('walks.show');
    Volt::route('walks/{walk}/edit', 'walks.edit')->name('walks.edit');
    Volt::route('walks/{walk}/report', 'walks.report')->name('walks.report');
    Route::get('walks/{walk}/report.pdf', WalkReportPdfController::class)->name('walks.report.pdf');
});

require __DIR__.'/auth.php';

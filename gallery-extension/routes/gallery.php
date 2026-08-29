<?php

use App\Http\Controllers\Gallery\AccountLoginController;
use App\Http\Controllers\Gallery\ActivityController;
use App\Http\Controllers\Gallery\LoginSecurityController;
use App\Http\Controllers\Gallery\OperationAuditController;
use App\Http\Middleware\GalleryApiMiddleware;
use Illuminate\Support\Facades\Route;

Route::middleware(['login_required:always', GalleryApiMiddleware::class])->group(function (): void {
	Route::get('/AccountLoginEvents', [AccountLoginController::class, 'index']);
	Route::post('/AccountLoginEvents', [AccountLoginController::class, 'store'])->middleware('throttle:30,1');
	Route::get('/Activities', [ActivityController::class, 'index']);
	Route::post('/Activities', [ActivityController::class, 'store'])->middleware('throttle:10,1')->withoutMiddleware(['content_type:json']);
	Route::get('/Activities/{activityId}/Images', [ActivityController::class, 'images']);
	Route::delete('/Activities/{activityId}', [ActivityController::class, 'destroy'])->middleware('throttle:30,1');
	Route::get('/Activities/{activityId}/Comments', [ActivityController::class, 'comments']);
	Route::post('/Activities/{activityId}/Comments', [ActivityController::class, 'storeComment'])->middleware('throttle:30,1');
	Route::get('/LoginSecurity', [LoginSecurityController::class, 'index']);
	Route::post('/LoginSecurity::trust', [LoginSecurityController::class, 'trustCurrentDevice'])->middleware('throttle:10,1');
	Route::post('/LoginSecurity::desktopProtection', [LoginSecurityController::class, 'setDesktopProtection'])->middleware('throttle:10,1');
	Route::post('/LoginSecurity::revoke/{id}', [LoginSecurityController::class, 'revokeDevice'])->middleware('throttle:10,1');
	Route::get('/OperationAuditEvents', [OperationAuditController::class, 'index']);
});

Route::get('/ActivityImages/{activityId}/{imageId}', [ActivityController::class, 'image'])
	->middleware(['login_required:always'])
	->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class, 'accept_content_type:json', 'content_type:json']);

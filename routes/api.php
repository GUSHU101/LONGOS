<?php

use App\Http\Controllers\HealthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/*
 * Lightweight, unauthenticated deployment probe. It never returns connection
 * details, credentials, or application settings; a 503 simply tells a load
 * balancer that this instance must not receive customer traffic yet.
 */
Route::get('/health', HealthController::class)->name('health');

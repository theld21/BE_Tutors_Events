<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('', [AuthController::class, 'index']);
Route::get('add-token', [AuthController::class, 'storeToken']);
Route::get('auth/get-url', [AuthController::class, 'getUrl']);
Route::get('auth/redirect', [AuthController::class, 'getUrl'])->name('getUrl');
Route::get('auth/checkpoint', [AuthController::class, 'checkpoint']);
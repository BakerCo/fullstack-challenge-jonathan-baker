<?php

use App\Http\Controllers\UsersWeatherController;
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

Route::get('/', function () {
    return response()->json([
        'message' => 'all systems are a go',
    ]);
});

// Users with basic weather
Route::get('/users', [UsersWeatherController::class, 'index']);
// Detailed weather for a user
Route::get('/users/{id}/weather', [UsersWeatherController::class, 'show'])->whereNumber('id');

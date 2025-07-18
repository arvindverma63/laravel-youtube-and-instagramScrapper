<?php

use App\Http\Controllers\InstagramScraperController;
use App\Http\Controllers\LinkedInScraperController;
use App\Http\Controllers\YouTubeScraperController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get("/youtube",[YouTubeScraperController::class,'index'])->name('youtube.index');
Route::post("/search",[YouTubeScraperController::class,'search'])->name('youtube.search');
Route::get('/linkedin', [LinkedInScraperController::class, 'index'])->name('linkedin.index');
Route::get('/linkedin/redirect', [LinkedInScraperController::class, 'redirectToLinkedIn'])->name('linkedin.redirect');
Route::get('/linkedin/callback', [LinkedInScraperController::class, 'callback'])->name('linkedin.callback');
Route::get('/linkedin/download', [LinkedInScraperController::class, 'download'])->name('linkedin.download');


Route::get('/instagram', [InstagramScraperController::class, 'index'])->name('instagram');
Route::post('/instagram', [InstagramScraperController::class, 'search'])->name('instagram.search');

<?php

use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

// Arayüz: api / sanctum / up dışındaki her yol derlenmiş Vue sayfasına düşer (Vue Router karşılar).
Route::get('/{yol?}', SpaController::class)->where('yol', '^(?!api/|api$|sanctum/|up$).*$')->name('spa');

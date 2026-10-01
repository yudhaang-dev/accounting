<?php

use Illuminate\Support\Facades\Route;

/*
| The first-party UI is a single-page app (ADR-0010). Laravel only serves its HTML shell; every
| business read and write goes through /api/v1. Auth actions (login, logout, 2FA, password reset)
| are Fortify's JSON routes. The named GET routes below exist because Fortify and Laravel build
| links and redirects from these names; the SPA router renders the actual screens.
*/

Route::view('/login', 'spa')->name('login')->middleware('guest');
Route::view('/forgot-password', 'spa')->name('password.request')->middleware('guest');
Route::view('/reset-password/{token}', 'spa')->name('password.reset')->middleware('guest');
Route::view('/two-factor-challenge', 'spa')->name('two-factor.login')->middleware('guest');
Route::view('/email/verify', 'spa')->name('verification.notice');

// Any other GET is a client-side route. API and API-docs paths are excluded so unknown or wrong-method
// API calls keep their JSON 404/405 instead of falling into the HTML shell.
Route::get('/{any}', fn () => view('spa'))->where('any', '^(?!(api|docs)(/|$)).*$');

<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::redirect('/privacy-policy', '/privacy-policy/en');
Route::view('/privacy-policy/en', 'legal.privacy-policy-en')->name('legal.privacy.en');
Route::view('/privacy-policy/ar', 'legal.privacy-policy-ar')->name('legal.privacy.ar');

Route::redirect('/terms-of-use', '/terms-of-use/en');
Route::view('/terms-of-use/en', 'legal.terms-of-use-en')->name('legal.terms.en');
Route::view('/terms-of-use/ar', 'legal.terms-of-use-ar')->name('legal.terms.ar');

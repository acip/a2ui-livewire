<?php

use App\Livewire\Gallery;
use App\Livewire\Playground;
use Illuminate\Support\Facades\Route;

Route::get('/', Gallery::class)->name('gallery');
Route::get('/agent', Playground::class)->name('agent');

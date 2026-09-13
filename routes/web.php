<?php

use App\Bus\NatsBus;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard', [
        'stream' => app(NatsBus::class)->streamName(),
        'consumer' => app(NatsBus::class)->monitorConsumerName(),
    ]);
});

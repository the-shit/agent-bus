<?php

use App\Providers\AppServiceProvider;
use App\Providers\NatsServiceProvider;
use Illuminate\Database\DatabaseServiceProvider;

return [
    DatabaseServiceProvider::class,
    AppServiceProvider::class,
    NatsServiceProvider::class,
];

<?php

use App\Providers\AppServiceProvider;
use App\Providers\NatsServiceProvider;
use Illuminate\Database\DatabaseServiceProvider;

return [

    'name' => 'agent-bus',

    'version' => app('git.version'),

    'env' => 'development',

    'providers' => [
        DatabaseServiceProvider::class,
        AppServiceProvider::class,
        NatsServiceProvider::class,
    ],

];

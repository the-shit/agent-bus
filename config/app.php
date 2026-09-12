<?php

use App\Providers\AppServiceProvider;
use App\Providers\NatsServiceProvider;

return [

    'name' => 'agent-bus',

    'version' => app('git.version'),

    'env' => 'development',

    'providers' => [
        AppServiceProvider::class,
        NatsServiceProvider::class,
    ],

];

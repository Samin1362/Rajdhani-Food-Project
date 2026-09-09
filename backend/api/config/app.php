<?php

declare(strict_types=1);

use Rajdhani\Support\Env;

return [
    'env'      => Env::get('APP_ENV', 'production'),
    'debug'    => Env::bool('APP_DEBUG', false),
    'url'      => Env::get('APP_URL', 'http://localhost:8000'),
    'timezone' => Env::get('APP_TIMEZONE', 'UTC'),

    // Every route in section 9 sits under this prefix.
    'api_prefix' => '/api/v1',

    'log_level' => Env::get('LOG_LEVEL', 'info'),
    'log_path'  => base_path('storage/logs'),

    // Guards the cron-invoked HTTP endpoints (section 16.4). Only required when
    // the host has no SSH and migrations must run over HTTP.
    'cron_token' => Env::get('CRON_TOKEN'),
];

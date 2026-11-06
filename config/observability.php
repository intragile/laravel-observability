<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Structured logging
    |--------------------------------------------------------------------------
    |
    | Read by IntrAgile\Observability\Logging\LokiJsonFormatter. Point a log
    | channel's "formatter" at that class to emit one JSON object per line.
    |
    */

    'logging' => [
        'service' => env('LOG_SERVICE', env('APP_NAME', 'laravel')),
        'include_stacktraces' => (bool) env('OBSERVABILITY_LOG_STACKTRACES', true),
        'redact_sql' => true,
        // Static fields added to every record, e.g. ['alias' => env('APP_ALIAS')].
        'extra' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Root execution guard
    |--------------------------------------------------------------------------
    |
    | Refuses to run Artisan as root in the listed environments unless the
    | named environment variable is set to a truthy value.
    |
    */

    'root_guard' => [
        'enabled' => true,
        'environments' => ['production'],
        'allow_env' => 'ARTISAN_ALLOW_ROOT',
    ],

    /*
    |--------------------------------------------------------------------------
    | Health checks
    |--------------------------------------------------------------------------
    |
    | Checks registered with spatie/laravel-health. A check whose optional
    | dependency is missing is skipped and reported by observability:doctor.
    |
    */

    'health' => [
        'register' => (bool) env('OBSERVABILITY_HEALTH_REGISTER', true),
        'checks' => [
            'schema_complete' => ['enabled' => true, 'listed' => 5, 'paths' => []],
            'failed_jobs' => ['enabled' => true, 'window_hours' => 24, 'listed' => 5],
            'mail_relay' => [
                'enabled' => true,
                'environments' => ['production'],
                'catcher_hosts' => ['mailhog', 'mailpit', 'sandbox.smtp.mailtrap.io', 'mailcatcher', 'maildev', 'smtp4dev'],
                'catcher_ports' => [1025, 1080, 8025],
                'hold_config_key' => null,
            ],
            'request_timing' => [
                'enabled' => true,
                'warn_ms' => 500,
                'fail_ms' => 1000,
                'routes' => [],
                'store' => null,
                'middleware_groups' => ['web', 'api'],
            ],
            'backup_freshness' => ['enabled' => true, 'warn_hours' => 26, 'fail_hours' => 48, 'store' => null],
            'broadcast' => [
                'enabled' => true,
                'connection' => 'reverb',
                'client_url' => env('OBSERVABILITY_BROADCAST_URL'),
                'timeout_seconds' => 5.0,
                'warn_ms' => 1000,
            ],
            'container_memory' => ['enabled' => true, 'root' => '/sys/fs/cgroup', 'warn_percent' => 90, 'fail_percent' => 95],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Prometheus
    |--------------------------------------------------------------------------
    |
    | Only used when spatie/laravel-prometheus is installed. Basic auth fails
    | closed: without a password every scrape is rejected.
    |
    */

    'prometheus' => [
        'basic_auth' => [
            'enabled' => true,
            'username' => env('PROMETHEUS_USERNAME', 'prometheus'),
            'password' => env('PROMETHEUS_PASSWORD'),
            'password_file' => env('PROMETHEUS_PASSWORD_FILE'),
        ],
        'container_memory_gauges' => true,
        'queue_collectors' => ['enabled' => false, 'connection' => null, 'queues' => ['default']],
    ],

    /*
    |--------------------------------------------------------------------------
    | Error digest
    |--------------------------------------------------------------------------
    |
    | Records reported exceptions and posts a grouped summary to a Slack
    | incoming webhook. Rows are marked as sent only after delivery succeeds.
    |
    */

    'error_digest' => [
        'enabled' => (bool) env('OBSERVABILITY_ERROR_DIGEST', false),
        'webhook_url' => env('OBSERVABILITY_SLACK_WEBHOOK'),
        'schedule' => '*/5 * * * *',
        'instance' => env('APP_ALIAS', env('APP_NAME', 'laravel')),
        'max_groups' => 10,
        'batch_size' => 500,
        'retention_days' => 30,
        // Buttons added to the message, e.g. ['Grafana' => 'https://grafana.example.com'].
        'links' => [],
    ],

];

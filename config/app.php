<?php

declare(strict_types=1);

return [
    'jwt_keys_dir' => getenv('JWT_KEYS_DIR') ?: '/var/www/data/keys',
    'jwt_kid' => getenv('JWT_KID') ?: 'iae-central-2026',
    'jwt_ttl' => (int) (getenv('JWT_TTL') ?: 3600),
    'db_path' => getenv('DB_PATH') ?: '/var/www/data/activity.db',
    'rabbitmq' => [
        'host' => getenv('RABBITMQ_HOST') ?: 'rabbitmq',
        'port' => (int) (getenv('RABBITMQ_PORT') ?: 5672),
        'user' => getenv('RABBITMQ_USER') ?: 'guest',
        'pass' => getenv('RABBITMQ_PASS') ?: 'guest',
        'exchange' => getenv('RABBITMQ_EXCHANGE') ?: 'iae.central.exchange',
        'board_queue' => getenv('RABBITMQ_BOARD_QUEUE') ?: 'iae.lab.board',
    ],
    'admin_key' => getenv('ADMIN_KEY') ?: 'admin-iae-dashboard',
];

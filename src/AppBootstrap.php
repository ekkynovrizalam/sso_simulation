<?php

declare(strict_types=1);

namespace Iae\Central;

use Iae\Central\Controllers\AdminController;
use Iae\Central\Controllers\AuthController;
use Iae\Central\Controllers\HealthController;
use Iae\Central\Controllers\MessageController;
use Iae\Central\Controllers\SoapAuditController;
use Iae\Central\Services\ActivityLogger;
use Iae\Central\Services\AuthService;
use Iae\Central\Services\RabbitMqService;
use Iae\Central\Services\SoapAuditService;
use Slim\Factory\AppFactory;
use Slim\App;

final class AppBootstrap
{
    public static function create(): App
    {
        $appConfig = require __DIR__ . '/../config/app.php';
        $apiKeys = require __DIR__ . '/../config/api_keys.php';
        $citizens = require __DIR__ . '/../config/citizens.php';

        $logger = new ActivityLogger($appConfig['db_path']);
        $authService = new AuthService(
            $apiKeys,
            $citizens,
            $appConfig['jwt_secret'],
            $appConfig['jwt_ttl']
        );
        $soapService = new SoapAuditService();
        $rabbitMq = new RabbitMqService(
            $appConfig['rabbitmq']['host'],
            $appConfig['rabbitmq']['port'],
            $appConfig['rabbitmq']['user'],
            $appConfig['rabbitmq']['pass'],
            $appConfig['rabbitmq']['exchange'],
        );

        $app = AppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(true, true, true);

        $authController = new AuthController($authService, $logger, $appConfig['jwt_ttl']);
        $soapController = new SoapAuditController($authService, $soapService, $logger);
        $messageController = new MessageController($authService, $rabbitMq, $logger);
        $adminController = new AdminController($logger, $appConfig['admin_key']);
        $healthController = new HealthController();

        $app->get('/health', [$healthController, 'check']);
        $app->post('/api/v1/auth/token', [$authController, 'token']);
        $app->post('/soap/v1/audit', [$soapController, 'audit']);
        $app->post('/api/v1/messages/publish', [$messageController, 'publish']);
        $app->get('/api/admin/dashboard', [$adminController, 'dashboard']);

        return $app;
    }
}

<?php

declare(strict_types=1);

namespace Iae\Central;

use Iae\Central\Controllers\AdminController;
use Iae\Central\Controllers\AuthController;
use Iae\Central\Controllers\BoardController;
use Iae\Central\Controllers\HealthController;
use Iae\Central\Controllers\LandingController;
use Iae\Central\Controllers\JwksController;
use Iae\Central\Controllers\MessageController;
use Iae\Central\Controllers\SoapAuditController;
use Iae\Central\Services\ActivityLogger;
use Iae\Central\Services\AuthService;
use Iae\Central\Services\RabbitMqService;
use Iae\Central\Services\RsaKeyManager;
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

        $keyManager = new RsaKeyManager(
            $appConfig['jwt_keys_dir'],
            $appConfig['jwt_kid'],
        );

        $logger = new ActivityLogger($appConfig['db_path']);
        $authService = new AuthService(
            $apiKeys,
            $citizens,
            $keyManager,
            $appConfig['jwt_ttl'],
        );
        $soapService = new SoapAuditService();
        $rabbitMq = new RabbitMqService(
            $appConfig['rabbitmq']['host'],
            $appConfig['rabbitmq']['port'],
            $appConfig['rabbitmq']['user'],
            $appConfig['rabbitmq']['pass'],
            $appConfig['rabbitmq']['exchange'],
            $appConfig['rabbitmq']['board_queue'],
        );

        $app = AppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(true, true, true);

        $authController = new AuthController($authService, $logger, $appConfig['jwt_ttl']);
        $jwksController = new JwksController($keyManager);
        $soapController = new SoapAuditController($authService, $soapService, $logger);
        $messageController = new MessageController($authService, $rabbitMq, $logger);
        $adminController = new AdminController($logger, $appConfig['admin_key']);
        $boardController = new BoardController($rabbitMq, $appConfig['rabbitmq']['exchange']);
        $healthController = new HealthController($rabbitMq);
        $landingController = new LandingController();

        $app->get('/', [$landingController, 'index']);
        $app->get('/board', [$boardController, 'index']);
        $app->get('/health', [$healthController, 'check']);
        $app->get('/api/v1/auth/jwks', [$jwksController, 'jwks']);
        $app->get('/.well-known/jwks.json', [$jwksController, 'jwks']);
        $app->post('/api/v1/auth/token', [$authController, 'token']);
        $app->post('/soap/v1/audit', [$soapController, 'audit']);
        $app->post('/api/v1/messages/publish', [$messageController, 'publish']);
        $app->get('/api/v1/messages/board', [$boardController, 'json']);
        $app->get('/api/v1/messages/board/search', [$boardController, 'search']);
        $app->get('/api/admin/dashboard', [$adminController, 'dashboard']);

        return $app;
    }
}

<?php

declare(strict_types=1);

use App\Application\Controllers\AuthController;
use App\Application\Controllers\AuditController;
use App\Application\Controllers\HomeController;
use App\Application\Controllers\FileController;
use App\Application\Controllers\ProfileController;
use App\Application\Controllers\SettingsController;
use App\Application\Controllers\UserController;
use App\Application\Middleware\JwtMiddleware;
use App\Application\Middleware\AdminMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;
use Slim\App;

return function (App $app): void {
    $app->options('/{routes:.*}', function (Request $request, Response $response) {
        return $response;
    });

    $app->group('/api', function (Group $group) {
        $group->group('/auth', function (Group $group) {
            $group->post('/login', [AuthController::class, 'login']);
            $group->post('/register', [AuthController::class, 'register']);
            $group->post('/refresh', [AuthController::class, 'refresh']);
            $group->post('/password/forgot', [AuthController::class, 'forgotPassword']);
            $group->post('/password/reset', [AuthController::class, 'resetPassword']);
            $group->post('/email/verify', [AuthController::class, 'verifyEmail']);
            $group->post('/email/resend', [AuthController::class, 'resendVerification']);
        });


        $group->group('', function (Group $group) {
            $group->get('', [HomeController::class, 'index']);
            $group->get('/settings', [SettingsController::class, 'publicSettings']);
        });


        // Self-service routes for authenticated users.
        $group->group('', function (Group $group) {
            $group->post('/auth/logout', [AuthController::class, 'logout']);
            $group->post('/auth/logout-all', [AuthController::class, 'logoutAll']);
            $group->get('/dashboard', [UserController::class, 'dashboard']);
            $group->get('/me', [ProfileController::class, 'show']);
            $group->patch('/me', [ProfileController::class, 'updateProfile']);
            $group->patch('/me/password', [ProfileController::class, 'changePassword']);
            $group->post('/me/avatar', [FileController::class, 'uploadAvatar']);
            $group->delete('/me', [ProfileController::class, 'destroy']);

            $group->group('/files', function (Group $group) {
                $group->get('', [FileController::class, 'list']);
                $group->post('', [FileController::class, 'upload']);
                $group->get('/{id:[1-9]\\d*}', [FileController::class, 'show']);
                $group->get('/{id:[1-9]\\d*}/download', [FileController::class, 'download']);
                $group->delete('/{id:[1-9]\\d*}', [FileController::class, 'destroy']);
            });

            // Backward-compatible profile endpoint.
            $group->post('/profile', [ProfileController::class, 'updateProfile']);
            $group->patch('/profile', [ProfileController::class, 'updateProfile']);
        })->add(JwtMiddleware::class);

        // Administrator-only user management.
        $group->group('/admin', function (Group $group) {
            $group->get('/system/info', [SettingsController::class, 'systemInfo']);

            $group->group('/audit-logs', function (Group $group) {
                $group->get('', [AuditController::class, 'list']);
                $group->get('/{id:[1-9]\\d*}', [AuditController::class, 'show']);
            });

            $group->group('/settings', function (Group $group) {
                $group->get('', [SettingsController::class, 'list']);
                $group->get('/{key:[a-z][a-z0-9_.-]{0,99}}', [SettingsController::class, 'show']);
                $group->put('/{key:[a-z][a-z0-9_.-]{0,99}}', [SettingsController::class, 'upsert']);
                $group->delete('/{key:[a-z][a-z0-9_.-]{0,99}}', [SettingsController::class, 'destroy']);
            });

            $group->group('/files', function (Group $group) {
                $group->get('', [FileController::class, 'adminList']);
                $group->get('/{id:[1-9]\\d*}', [FileController::class, 'show']);
                $group->get('/{id:[1-9]\\d*}/download', [FileController::class, 'download']);
                $group->delete('/{id:[1-9]\\d*}', [FileController::class, 'destroy']);
            });

            $group->group('/users', function (Group $group) {
                $group->get('/stats', [UserController::class, 'stats']);
                $group->get('', [UserController::class, 'list']);
                $group->post('', [UserController::class, 'store']);
                $group->get('/{id:[1-9]\\d*}', [UserController::class, 'show']);
                $group->patch('/{id:[1-9]\\d*}', [UserController::class, 'update']);
                $group->patch('/{id:[1-9]\\d*}/status', [UserController::class, 'updateStatus']);
                $group->delete('/{id:[1-9]\\d*}', [UserController::class, 'destroy']);
            });
        })->add(AdminMiddleware::class)->add(JwtMiddleware::class);

        // Legacy user routes are now administrator-only.
        $group->group('/users', function (Group $group) {
            $group->get('', [UserController::class, 'list']);
            $group->post('', [UserController::class, 'store']);
            $group->get('/{id:[1-9]\\d*}', [UserController::class, 'show']);
        })->add(AdminMiddleware::class)->add(JwtMiddleware::class);
    });
};

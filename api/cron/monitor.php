<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Exceptions\StateStoreUnavailableException;
use App\Services\CronAuthenticator;
use App\Services\CronHealthCheck;
use App\Services\DefiLlamaPriceService;
use App\Services\MonitorConfigFactory;
use App\Services\MonitorRunner;
use App\Services\UpstashRedisStateStore;

function jsonResponse(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function validateCronAuth(): void
{
    $expectedSecret = getenv('CRON_SECRET');
    $result = (new CronAuthenticator())->validate(
        $expectedSecret === false ? null : $expectedSecret,
        $_SERVER['HTTP_AUTHORIZATION'] ?? ''
    );

    if ($result['statusCode'] !== 200) {
        jsonResponse($result['statusCode'], $result['body']);
    }
}

validateCronAuth();

if (($_GET['health'] ?? null) === '1') {
    $healthCheck = new CronHealthCheck(
        static fn (): array => MonitorConfigFactory::fromEnvironment(),
        static function (): void {
            $stateStore = new UpstashRedisStateStore();
            $stateStore->assertAvailable();
        }
    );
    $healthResult = $healthCheck->check();

    jsonResponse($healthResult['statusCode'], $healthResult['body']);
}

function buildTelegramNotifier(): callable
{
    $botToken = getenv('TELEGRAM_BOT_TOKEN');
    $chatId = getenv('TELEGRAM_CHAT_ID');

    if ($botToken === false || $botToken === '' || $chatId === false || $chatId === '') {
        throw new RuntimeException('Telegram environment variables are required.');
    }

    return static function (string $message) use ($botToken, $chatId): void {
        $handle = curl_init('https://api.telegram.org/bot' . $botToken . '/sendMessage');
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
            ],
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $result = curl_exec($handle);
        $error = curl_error($handle);
        curl_close($handle);

        if ($result === false || $error !== '') {
            throw new RuntimeException('Telegram notification failed.');
        }
    };
}

$runtimeMode = 'vercel';
$stateBackend = 'upstash';

try {
    $notificationsEnabled = filter_var(getenv('NOTIFICATIONS_ENABLED') ?: 'true', FILTER_VALIDATE_BOOL);
    $config = MonitorConfigFactory::fromEnvironment();
    $stateStore = new UpstashRedisStateStore();
    $stateStore->assertAvailable();
    $notifier = $notificationsEnabled ? buildTelegramNotifier() : null;
    $runner = new MonitorRunner(
        $config,
        new DefiLlamaPriceService(),
        $stateStore,
        $notificationsEnabled,
        $notifier,
        false
    );
    $result = $runner->run();

    if ($result['errors'] !== []) {
        jsonResponse(500, [
            'status' => 'error',
            'positionsChecked' => $result['positionsChecked'],
            'alertsSent' => 0,
            'runtimeMode' => $runtimeMode,
            'stateBackend' => $stateBackend,
            'errors' => $result['errors'],
        ]);
    }

    jsonResponse(200, [
        'status' => 'success',
        'positionsChecked' => $result['positionsChecked'],
        'alertsSent' => $result['alertsSent'],
        'runtimeMode' => $runtimeMode,
        'stateBackend' => $stateBackend,
        'errors' => $result['errors'],
    ]);
} catch (StateStoreUnavailableException) {
    jsonResponse(500, [
        'status' => 'error',
        'positionsChecked' => 0,
        'alertsSent' => 0,
        'runtimeMode' => $runtimeMode,
        'stateBackend' => $stateBackend,
        'errors' => ['State backend unavailable.'],
    ]);
} catch (Throwable) {
    jsonResponse(500, [
        'status' => 'error',
        'positionsChecked' => 0,
        'alertsSent' => 0,
        'runtimeMode' => $runtimeMode,
        'stateBackend' => $stateBackend,
        'errors' => ['Monitor initialization or execution failed.'],
    ]);
}

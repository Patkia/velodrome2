<?php

declare(strict_types=1);

$botApiKey = (string) getenv('TELEGRAM_BOT_TOKEN');
$chatId = (string) getenv('TELEGRAM_CHAT_ID');
$telegramPath = 'https://api.telegram.org/bot' . $botApiKey;

function sendNotify(string $msg): string|false
{
    global $chatId, $telegramPath;

    if ($chatId === '' || str_ends_with($telegramPath, '/bot')) {
        throw new RuntimeException('Telegram environment variables are not configured.');
    }

    return file_get_contents($telegramPath . '/sendmessage?chat_id=' . $chatId . '&text=' . $msg . '&parse_mode=html');
}

function sendNotify2(string $msg): string|false
{
    global $botApiKey, $chatId;

    if ($botApiKey === '' || $chatId === '') {
        throw new RuntimeException('Telegram environment variables are not configured.');
    }

    $ch = curl_init('https://api.telegram.org/bot' . $botApiKey . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'chat_id' => $chatId,
            'text' => $msg,
            'parse_mode' => 'HTML',
        ],
        CURLOPT_RETURNTRANSFER => true,
    ]);

    $result = curl_exec($ch);
    curl_close($ch);

    return $result;
}

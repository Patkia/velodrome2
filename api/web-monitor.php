<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\DefiLlamaPriceService;
use App\Services\MonitorConfigFactory;
use App\Services\MonitorRunner;

header('Content-Type: text/html; charset=utf-8');

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

try {
    $runner = new MonitorRunner(
        MonitorConfigFactory::fromEnvironment(),
        new DefiLlamaPriceService(),
        null,
        false,
        null,
        false
    );
    $result = $runner->run();
    $errorMessage = $result['errors'] === []
        ? null
        : 'ไม่สามารถตรวจสอบข้อมูลจากผู้ให้บริการภายนอกได้ในขณะนี้ โปรดลองใหม่ภายหลัง';
    if ($errorMessage !== null) {
        http_response_code(503);
    }
} catch (Throwable) {
    $result = ['positionsChecked' => 0, 'positions' => [], 'errors' => []];
    $errorMessage = 'ไม่สามารถตรวจสอบข้อมูลได้ในขณะนี้ โปรดลองใหม่ภายหลัง';
    http_response_code(503);
}
?><!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Velodrome Position Monitor</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f7f7fb; color: #1f2430; margin: 0; }
        main { max-width: 760px; margin: 32px auto; padding: 0 20px; }
        article, .notice { background: #fff; border-radius: 12px; margin: 16px 0; padding: 20px; box-shadow: 0 1px 4px #0001; }
        h1 { margin-bottom: 8px; } h2 { margin: 0 0 12px; } p { line-height: 1.5; margin: 5px 0; }
        .ok { color: #087f23; font-weight: bold; } .bad { color: #b42318; font-weight: bold; }
        .notice { border-left: 4px solid #b42318; } .muted { color: #667085; }
    </style>
</head>
<body>
<main>
    <h1>Velodrome Position Monitor</h1>
    <p class="muted">Manual read-only check — ไม่ส่ง Telegram และไม่เปลี่ยน state</p>
    <?php if ($errorMessage !== null): ?>
        <div class="notice"><?= escapeHtml($errorMessage) ?></div>
    <?php elseif ($result['positions'] === []): ?>
        <div class="notice">ไม่พบ Position ที่ stake อยู่ใน gauge ที่ตั้งค่าไว้</div>
    <?php else: ?>
        <p class="muted">ตรวจพบ <?= (int) $result['positionsChecked'] ?> Position</p>
        <?php foreach ($result['positions'] as $position): ?>
            <article>
                <h2>[<?= escapeHtml($position['chain']) ?>] Position #<?= (int) $position['positionId'] ?></h2>
                <p><strong>Pool:</strong> <?= escapeHtml($position['pool']) ?></p>
                <p><strong>Initial Value:</strong> <?= escapeHtml($position['initialValue']) ?></p>
                <p><strong>Current Value:</strong> <?= escapeHtml($position['currentValue']) ?></p>
                <p><strong>P/L:</strong> <?= escapeHtml($position['profitLoss']) ?></p>
                <?php foreach ($position['tokenLines'] as $line): ?>
                    <p><?= escapeHtml($line) ?></p>
                <?php endforeach; ?>
                <p><?= escapeHtml($position['reward']) ?></p>
                <p class="<?= $position['inRange'] ? 'ok' : 'bad' ?>">
                    <?= $position['inRange'] ? '🟢 IN RANGE' : '🔴 OUT OF RANGE' ?>
                </p>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</main>
</body>
</html>

<?php

declare(strict_types=1);

use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$rootPath = dirname(__DIR__);
if (is_file($rootPath . '/.env')) {
    Dotenv::createImmutable($rootPath)->safeLoad();
}

$startParam = trim((string) ($_GET['start'] ?? ''));
$botUsername = trim((string) ($_ENV['BOT_USERNAME'] ?? $_SERVER['BOT_USERNAME'] ?? getenv('BOT_USERNAME') ?: ''));

if ($startParam !== '' && preg_match('/^u_[a-z0-9]{6,20}$/', $startParam) === 1 && $botUsername !== '') {
    $targetUrl = sprintf('https://t.me/%s?start=%s', $botUsername, urlencode($startParam));
    header('Location: ' . $targetUrl, true, 302);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ربات پیام ناشناس</title>
</head>
<body style="font-family: Tahoma, sans-serif; max-width: 680px; margin: 40px auto; line-height: 1.8;">
<h1>ربات پیام ناشناس</h1>
<p>برای استفاده از ربات، لینک اختصاصی را در تلگرام باز کنید.</p>
<?php if ($startParam !== '' && $botUsername === ''): ?>
    <p style="color:#a00;">متغیر <code>BOT_USERNAME</code> در فایل <code>.env</code> تنظیم نشده است.</p>
<?php endif; ?>
<p>
    برای دریافت لینک اختصاصی:
    <a href="https://t.me" rel="noopener noreferrer" target="_blank">تلگرام</a>
</p>
</body>
</html>


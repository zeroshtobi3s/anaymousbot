<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Run polling from CLI:\nphp cli/polling.php\n";
    exit;
}

require dirname(__DIR__) . '/cli/polling.php';


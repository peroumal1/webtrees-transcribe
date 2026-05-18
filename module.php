<?php

declare(strict_types=1);

use Peroumal1\WebtreesTranscribe\TranscribeModule;

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

return new TranscribeModule();

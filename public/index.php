<?php

use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

$timezone = $_SERVER['APP_TIMEZONE'] ?? $_ENV['APP_TIMEZONE'] ?? 'America/Argentina/Buenos_Aires';
if (is_string($timezone) && $timezone !== '') {
    date_default_timezone_set($timezone);
}

return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};

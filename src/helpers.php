<?php

declare(strict_types=1);

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $val = $_ENV[$key] ?? getenv($key);
        if ($val === false) return $default;
        return match (strtolower((string)$val)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            default            => $val,
        };
    }
}

if (!function_exists('json_response')) {
    function json_response(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('request_body')) {
    function request_body(): array
    {
        $raw = file_get_contents('php://input');
        if (empty($raw)) return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('abort')) {
    function abort(int $status, string $message = ''): void
    {
        json_response(['error' => $message ?: 'Error'], $status);
    }
}

if (!function_exists('log_error')) {
    function log_error(string $message, array $context = []): void
    {
        $line = date('Y-m-d H:i:s') . ' ERROR: ' . $message;
        if (!empty($context)) {
            $line .= ' ' . json_encode($context);
        }
        $logFile = BASE_PATH . '/storage/logs/app.log';
        @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        error_log($line);
    }
}

if (!function_exists('now')) {
    function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}

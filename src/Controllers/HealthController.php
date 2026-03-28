<?php

declare(strict_types=1);

namespace Aivo\Controllers;

class HealthController
{
    public function index(): void
    {
        json_response([
            'status'  => 'ok',
            'service' => 'AIVO Optimize API',
            'version' => '2.0.0',
            'time'    => date('c'),
        ]);
    }

// Appended

    public function dashboard(): void
    {
        $file = BASE_PATH . '/public/probe-intelligence.html';
        if (!file_exists($file)) {
            http_response_code(404);
            echo 'Dashboard not found';
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        readfile($file);
        exit;
    }
}

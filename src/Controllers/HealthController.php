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
}

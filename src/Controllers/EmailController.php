<?php

declare(strict_types=1);

namespace Aivo\Controllers;

class EmailController
{
    public function handle(): void
    {
        $body       = request_body();
        $to         = $body['to']         ?? null;
        $subject    = $body['subject']    ?? null;
        $text       = $body['text']       ?? null;
        $from_name  = $body['from_name']  ?? 'AIVO Search';
        $from_email = $body['from_email'] ?? null;

        if (!$to || !$subject || !$text || !$from_email) {
            abort(422, 'Missing required fields');
        }

        $resend_key = env('RESEND_API_KEY');
        if (empty($resend_key)) {
            abort(503, 'Resend not configured');
        }

        $payload = json_encode([
            'from'    => $from_name . ' <' . $from_email . '>',
            'to'      => [$to],
            'subject' => $subject,
            'text'    => $text,
        ]);

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $resend_key,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        header('Content-Type: application/json');
        http_response_code($status);
        echo $response;
        exit;
    }
}

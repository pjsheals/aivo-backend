<?php

declare(strict_types=1);

namespace Aivo\Controllers;

class ProxyController
{
    // ── POST /api/proxy ──────────────────────────────────────────
    // The HTML sends { platform, messages, system }.
    // This looks up the key server-side, calls the AI API, and
    // streams the response back in normalised OpenAI SSE format.
    // Keys never leave the server.
    public function handle(): void
    {
        $body     = request_body();
        $platform = $body['platform'] ?? null;
        $messages = $body['messages'] ?? [];
        $system   = $body['system']   ?? '';

        $allowed = ['chatgpt', 'perplexity', 'gemini', 'grok', 'claude'];
        if (!$platform || !in_array($platform, $allowed, true)) {
            abort(422, 'Invalid platform');
        }

        // ── Platform config — keys read from Railway env vars ────
        $platforms = [
            'chatgpt' => [
                'url'    => 'https://api.openai.com/v1/chat/completions',
                'model'  => 'gpt-4o',
                'format' => 'openai',
                'key'    => env('OPENAI_API_KEY'),
            ],
            'perplexity' => [
                'url'    => 'https://api.perplexity.ai/chat/completions',
                'model'  => 'sonar-pro',
                'format' => 'openai',
                'key'    => env('PERPLEXITY_API_KEY'),
            ],
            'gemini' => [
                'url'    => 'https://generativelanguage.googleapis.com/v1beta/models',
                'model'  => 'gemini-2.0-flash',
                'format' => 'gemini',
                'key'    => env('GEMINI_API_KEY'),
            ],
            'grok' => [
                'url'    => 'https://api.x.ai/v1/chat/completions',
                'model' => 'grok-4-1-fast-non-reasoning',
                'format' => 'openai',
                'key'    => env('GROK_API_KEY'),
            ],
            'claude' => [
                'url'    => 'https://api.anthropic.com/v1/messages',
                'model'  => 'claude-sonnet-4-20250514',
                'format' => 'anthropic',
                'key'    => env('ANTHROPIC_API_KEY'),
            ],
        ];

        $cfg = $platforms[$platform];

        if (empty($cfg['key'])) {
            abort(503, 'Platform not configured');
        }

        // ── Build request for each AI format ────────────────────
        if ($cfg['format'] === 'openai') {
            $body_json = json_encode([
                'model'      => $cfg['model'],
                'messages'   => $system
                    ? array_merge([['role' => 'system', 'content' => $system]], $messages)
                    : $messages,
                'stream'     => true,
                'max_tokens' => $body['max_tokens'] ?? 480,
            ]);
            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $cfg['key'],
            ];
            $url = $cfg['url'];

        } elseif ($cfg['format'] === 'gemini') {
            $contents = array_map(fn($m) => [
                'role'  => $m['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $m['content']]],
            ], $messages);
            $body_json = json_encode([
                'system_instruction' => ['parts' => [['text' => $system]]],
                'contents'           => $contents,
                'generationConfig'   => ['maxOutputTokens' => 600, 'temperature' => 0.7],
                'safetySettings'     => [
                    ['category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_ONLY_HIGH'],
                    ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_ONLY_HIGH'],
                    ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_ONLY_HIGH'],
                    ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
                ],
            ]);
            $headers = ['Content-Type: application/json'];
            $url = $cfg['url'] . '/' . $cfg['model'] . ':streamGenerateContent?alt=sse&key=' . $cfg['key'];

        } else { // anthropic
            $body_json = json_encode([
    'model'      => $cfg['model'],
    'max_tokens' => $body['max_tokens'] ?? 480,
                'stream'     => true,
                'system'     => $system,
                'messages'   => $messages,
            ]);
            $headers = [
                'Content-Type: application/json',
                'x-api-key: ' . $cfg['key'],
                'anthropic-version: 2023-06-01',
            ];
            $url = $cfg['url'];
        }

        // ── Stream response back to HTML ─────────────────────────
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');

        $format = $cfg['format'];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body_json,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use ($format) {
                foreach (explode("\n", $chunk) as $line) {
                    $line = trim($line);
                    if (!str_starts_with($line, 'data: ')) continue;
                    $data = substr($line, 6);
                    if ($data === '[DONE]') {
                        echo "data: [DONE]\n\n";
                        if (ob_get_level() > 0) ob_flush();
                        flush();
                        continue;
                    }
                    try {
                        $tok = null;
                        if ($format === 'openai') {
                            $parsed = json_decode($data, true);
                            $tok    = $parsed['choices'][0]['delta']['content'] ?? null;

                        } elseif ($format === 'gemini') {
                            $parsed = json_decode($data, true);
                            if (($parsed['candidates'][0]['finishReason'] ?? '') === 'SAFETY') {
                                $tok = '[Response filtered by safety settings]';
                            } else {
                                $tok = $parsed['candidates'][0]['content']['parts'][0]['text'] ?? null;
                            }

                        } elseif ($format === 'anthropic') {
                            $parsed = json_decode($data, true);
                            if (($parsed['type'] ?? '') === 'content_block_delta'
                                && ($parsed['delta']['type'] ?? '') === 'text_delta') {
                                $tok = $parsed['delta']['text'] ?? null;
                            }
                        }

                        if ($tok !== null) {
                            $out = json_encode(['choices' => [['delta' => ['content' => $tok]]]]);
                            echo "data: {$out}\n\n";
                            if (ob_get_level() > 0) ob_flush();
                            flush();
                        }
                    } catch (\Throwable $e) {
                        // Skip malformed chunks silently
                    }
                }
                return strlen($chunk);
            },
        ]);

        curl_exec($ch);
        curl_close($ch);
        exit;
    }
}

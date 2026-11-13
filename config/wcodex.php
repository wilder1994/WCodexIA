<?php

declare(strict_types=1);

return [

    /*
    | El cliente no elige el proveedor. Por defecto el modelo es local
    | y el código no sale de la máquina.
    */
    'api_token' => env('WCODEX_API_TOKEN', ''),

    'allow_external' => (bool) env('WCODEX_ALLOW_EXTERNAL', false),

    'workspace' => env('WCODEX_WORKSPACE') ?: base_path(),

    'history_limit' => 10,

    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434'),
        'model' => env('OLLAMA_MODEL', 'qwen2.5-coder:3b'),
        'timeout' => (int) env('OLLAMA_TIMEOUT', 120),
    ],

    'tesseract' => [
        'binary' => env('TESSERACT_BINARY', ''),
        'tessdata' => env('TESSERACT_TESSDATA', ''),
        'languages' => env('TESSERACT_LANGS', 'spa+eng'),
    ],

    'policies' => [
        'external_egress' => true,
        'prompt_injection' => true,
        'offensive_request' => true,
        'secret_redaction' => true,
    ],

];

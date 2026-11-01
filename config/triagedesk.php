<?php

$list = fn (string $value): array => array_values(array_filter(array_map('trim', explode(',', $value))));

return [

    /*
    |--------------------------------------------------------------------------
    | Providers and routing
    |--------------------------------------------------------------------------
    |
    | Each agent tries its providers in order. A provider is skipped when it
    | has no API key in .env or has spent its daily limit, and the next one
    | takes over; the SDK also fails over when a provider is down or rate
    | limited. Leave a model blank to use that provider's cheapest model.
    |
    | A daily limit of 0 switches that provider off.
    |
    */

    'providers' => [
        'anthropic' => [
            'model' => env('AI_ANTHROPIC_MODEL') ?: null,
            'daily_limit_usd' => (float) env('AI_ANTHROPIC_DAILY_LIMIT_USD', 3),
        ],
        'openai' => [
            'model' => env('AI_OPENAI_MODEL') ?: null,
            'daily_limit_usd' => (float) env('AI_OPENAI_DAILY_LIMIT_USD', 6),
        ],
        'gemini' => [
            'model' => env('AI_GEMINI_MODEL') ?: null,
            'daily_limit_usd' => (float) env('AI_GEMINI_DAILY_LIMIT_USD', 0),
        ],
    ],

    // Short structured classification: OpenAI first, since it has the larger daily allowance.
    'triage_providers' => $list(env('AI_TRIAGE_PROVIDERS', 'openai,anthropic')),

    // The tool-using agent: Claude first, since its approval flow is the one tested end to end.
    'support_providers' => $list(env('AI_SUPPORT_PROVIDERS', 'anthropic,openai')),

    // Days for the daily limits start at midnight in this time zone.
    'budget_timezone' => env('AI_BUDGET_TIMEZONE', 'Asia/Shanghai'),

    /*
    |--------------------------------------------------------------------------
    | Prices
    |--------------------------------------------------------------------------
    |
    | List prices in US dollars per million tokens, used to count each day's
    | spend from the token usage every model call reports. A model id matches
    | the longest key it starts with. "long" prices apply to a call whose
    | input is over "long_over" tokens. Cache prices default to 0.1x (read)
    | and 1.25x (write) of the input price. Models not listed are counted at
    | the "unknown" price, which is set high on purpose so a missing entry
    | stops early instead of overspending.
    |
    | Checked 2026-11-01: Anthropic (Claude API docs) and OpenAI (model pages).
    |
    */

    'prices' => [
        'claude-haiku-5-5' => [
            'input' => 0.10, 'output' => 0.50, 'cache_read' => 0.01, 'cache_write' => 0.125,
            'long_over' => 100_000,
            'long' => ['input' => 0.50, 'output' => 2.50, 'cache_read' => 0.05, 'cache_write' => 0.625],
        ],
        'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00, 'cache_read' => 0.20, 'cache_write' => 2.50],
        'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00, 'cache_read' => 0.20, 'cache_write' => 5.00],
        'gpt-6-luna' => [
            'input' => 0.10, 'output' => 0.50, 'cache_read' => 0.01, 'cache_write' => 0.125,
            'long_over' => 272_000,
            'long' => ['input' => 0.20, 'output' => 0.75, 'cache_read' => 0.02, 'cache_write' => 0.25],
        ],
        'gpt-6-sol' => [
            'input' => 2.00, 'output' => 10.00, 'cache_read' => 0.20, 'cache_write' => 2.50,
            'long_over' => 272_000,
            'long' => ['input' => 4.00, 'output' => 15.00, 'cache_read' => 0.40, 'cache_write' => 5.00],
        ],
        'unknown' => [
            'input' => (float) env('AI_UNKNOWN_MODEL_INPUT_PRICE', 10),
            'output' => (float) env('AI_UNKNOWN_MODEL_OUTPUT_PRICE', 50),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Approvals
    |--------------------------------------------------------------------------
    |
    | A refund runs without a human only while it, plus what was already
    | refunded on the same payment and what was auto-refunded to the same
    | customer within the window (on any ticket), stays at or below this
    | many US dollars.
    |
    */

    'refund_auto_approve_limit' => (float) env('REFUND_AUTO_APPROVE_LIMIT', 50),

    'refund_auto_approve_window_days' => (int) env('REFUND_AUTO_APPROVE_WINDOW_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Public Demo Guardrails
    |--------------------------------------------------------------------------
    |
    | In demo mode, visitors browse recorded runs; only someone with the access
    | code can start live model runs. Without an access code nobody can.
    |
    */

    'demo' => [
        'enabled' => (bool) env('DEMO_MODE', false),
        'access_code' => env('DEMO_ACCESS_CODE') ?: null,
        'live_runs_per_day' => (int) env('DEMO_LIVE_RUNS_PER_DAY', 400),
        'chats_per_ip_per_day' => (int) env('DEMO_CHATS_PER_IP_PER_DAY', 15),
        'triages_per_ip_per_day' => (int) env('DEMO_TRIAGES_PER_IP_PER_DAY', 10),
        'run_token_limit' => (int) env('AI_RUN_TOKEN_LIMIT', 40000),
        'recordings_path' => env('DEMO_RECORDINGS_PATH', storage_path('app/recordings.json')),
    ],

];

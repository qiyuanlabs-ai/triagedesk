<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | The provider comes from AI_PROVIDER (see config/ai.php). Leave a model
    | blank to use that provider's own default: the cheapest model for
    | triage and the default model for the ticket chat.
    |
    */

    'triage_model' => env('AI_TRIAGE_MODEL') ?: null,

    'support_model' => env('AI_SUPPORT_MODEL') ?: null,

    /*
    |--------------------------------------------------------------------------
    | Approvals
    |--------------------------------------------------------------------------
    |
    | Refunds at or below this amount (in US dollars) run without a human.
    |
    */

    'refund_auto_approve_limit' => (float) env('REFUND_AUTO_APPROVE_LIMIT', 50),

    /*
    |--------------------------------------------------------------------------
    | Public Demo Guardrails
    |--------------------------------------------------------------------------
    */

    'demo' => [
        'enabled' => (bool) env('DEMO_MODE', false),
        'chats_per_ip_per_day' => (int) env('DEMO_CHATS_PER_IP_PER_DAY', 20),
        'triages_per_ip_per_day' => (int) env('DEMO_TRIAGES_PER_IP_PER_DAY', 20),
        'run_token_limit' => (int) env('AI_RUN_TOKEN_LIMIT', 40000),
        'daily_token_budget' => (int) env('AI_DAILY_TOKEN_BUDGET', 1000000),
    ],

];

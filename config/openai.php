<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OpenAI API Key and Organization
    |--------------------------------------------------------------------------
    |
    | Here you may specify your OpenAI API Key and organization. This will be
    | used to authenticate with the OpenAI API - you can find your API key
    | and organization on your OpenAI dashboard, at https://openai.com.
    */

    'api_key' => env('OPENAI_API_KEY'),
    'organization' => env('OPENAI_ORGANIZATION'),

    /*
    |--------------------------------------------------------------------------
    | OpenAI API Project
    |--------------------------------------------------------------------------
    |
    | Here you may specify your OpenAI API project. This is used optionally in
    | situations where you are using a legacy user API key and need association
    | with a project. This is not required for the newer API keys.
    */
    'project' => env('OPENAI_PROJECT'),

    /*
    |--------------------------------------------------------------------------
    | OpenAI Base URL
    |--------------------------------------------------------------------------
    |
    | Here you may specify your OpenAI API base URL used to make requests. This
    | is needed if using a custom API endpoint. Defaults to: api.openai.com/v1
    */
    'base_uri' => env('OPENAI_BASE_URL'),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | The timeout may be used to specify the maximum number of seconds to wait
    | for a response. By default, the client will time out after 30 seconds.
    */

    'request_timeout' => env('OPENAI_REQUEST_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Realtime Defaults
    |--------------------------------------------------------------------------
    |
    | These defaults keep OpenAI Realtime model choices centralized so the
    | frontend does not need to hard-code model snapshots or session details.
    */

    'realtime' => [
        'base_url' => env('OPENAI_REALTIME_BASE_URL', env('OPENAI_BASE_URL', 'https://api.openai.com/v1')),
        'timeout' => (int) env('OPENAI_REALTIME_TIMEOUT', env('OPENAI_REQUEST_TIMEOUT', 30)),
        'connect_timeout' => (int) env('OPENAI_REALTIME_CONNECT_TIMEOUT', 10),
        'retries' => (int) env('OPENAI_REALTIME_RETRIES', 2),
        'retry_delay_ms' => (int) env('OPENAI_REALTIME_RETRY_DELAY_MS', 200),
        'tool_test_timeout' => (int) env('OPENAI_REALTIME_TOOL_TEST_TIMEOUT', 5),
        'tool_test_connect_timeout' => (int) env('OPENAI_REALTIME_TOOL_TEST_CONNECT_TIMEOUT', 3),
        'tool_test_client_secret_ttl' => (int) env('OPENAI_REALTIME_TOOL_TEST_CLIENT_SECRET_TTL', 60),
        'copilot_model' => env('OPENAI_REALTIME_COPILOT_MODEL', 'gpt-realtime-2.1'),
        'transcription_model' => env('OPENAI_REALTIME_TRANSCRIPTION_MODEL', 'gpt-realtime-whisper'),
        'transcription_delay' => env('OPENAI_REALTIME_TRANSCRIPTION_DELAY', 'low'),
        'reasoning_effort' => env('OPENAI_REALTIME_REASONING_EFFORT', 'low'),
        'client_secret_ttl' => (int) env('OPENAI_REALTIME_CLIENT_SECRET_TTL', 600),
        'max_output_tokens' => (int) env('OPENAI_REALTIME_MAX_OUTPUT_TOKENS', 1024),
        'context_token_limit' => (int) env('OPENAI_REALTIME_CONTEXT_TOKEN_LIMIT', 8000),
        'truncation_retention_ratio' => (float) env('OPENAI_REALTIME_TRUNCATION_RETENTION_RATIO', 0.8),
        'safety_identifier_seed' => env('OPENAI_REALTIME_SAFETY_IDENTIFIER_SEED', 'clueless-desktop'),
        'voice' => env('OPENAI_REALTIME_VOICE', 'marin'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Recall Copilot Analysis
    |--------------------------------------------------------------------------
    |
    | Recall already provides finalized, speaker-attributed text. The default
    | driver sends that text through the server-owned Responses API path while
    | local audio capture continues to use Realtime.
    */

    'recall_analysis' => [
        'driver' => env('OPENAI_RECALL_ANALYSIS_DRIVER', 'responses'),
        'base_url' => env('OPENAI_RESPONSES_BASE_URL', env('OPENAI_BASE_URL', 'https://api.openai.com/v1')),
        'model' => env('OPENAI_RECALL_ANALYSIS_MODEL', 'gpt-5.6-luna'),
        'reasoning_effort' => env('OPENAI_RECALL_ANALYSIS_REASONING_EFFORT', 'low'),
        'timeout' => (int) env('OPENAI_RECALL_ANALYSIS_TIMEOUT', 20),
        'connect_timeout' => (int) env('OPENAI_RECALL_ANALYSIS_CONNECT_TIMEOUT', 5),
        'retries' => (int) env('OPENAI_RECALL_ANALYSIS_RETRIES', 1),
        'retry_delay_ms' => (int) env('OPENAI_RECALL_ANALYSIS_RETRY_DELAY_MS', 200),
        'max_output_tokens' => (int) env('OPENAI_RECALL_ANALYSIS_MAX_OUTPUT_TOKENS', 1024),
        'prompt_cache_key' => env('OPENAI_RECALL_ANALYSIS_PROMPT_CACHE_KEY', 'clueless-recall-copilot-v1'),
    ],
];

<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

pest()->extend(Tests\TestCase::class)
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Create a mock API key for testing
 */
function mockApiKey(): string
{
    return 'sk-test-'.str_repeat('x', 40);
}

/**
 * Mock a successful OpenAI GA Realtime client secret response
 */
function mockRealtimeClientSecretResponse(array $overrides = []): array
{
    return array_replace_recursive([
        'value' => 'ek_'.bin2hex(random_bytes(32)),
        'expires_at' => time() + 600,
        'session' => [
            'type' => 'realtime',
            'object' => 'realtime.session',
            'id' => 'sess_'.uniqid(),
            'model' => 'gpt-realtime-2.1',
            'output_modalities' => ['text'],
            'tools' => [],
        ],
    ], $overrides);
}

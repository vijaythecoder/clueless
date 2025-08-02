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
 * Mock a successful OpenAI ephemeral key response
 */
function mockEphemeralKeyResponse(): array
{
    return [
        'id' => 'sess_'.uniqid(),
        'object' => 'realtime.session',
        'model' => 'gpt-4o-mini-realtime-preview-2024-12-17',
        'client_secret' => [
            'value' => 'ek_'.bin2hex(random_bytes(32)),
            'expires_at' => time() + 7200, // 2 hours from now
        ],
        'tools' => [],
    ];
}

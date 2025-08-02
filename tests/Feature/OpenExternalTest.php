<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Native\Laravel\Facades\Shell;
use Tests\TestCase;

class OpenExternalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset Shell facade mock for each test
        Shell::clearResolvedInstance('Shell');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_opens_valid_url_successfully(): void
    {
        Shell::shouldReceive('openExternal')
            ->once()
            ->with('https://github.com/vijaythecoder/clueless');

        $response = $this->postJson('/api/open-external', [
            'url' => 'https://github.com/vijaythecoder/clueless',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }

    public function test_opens_openai_url_successfully(): void
    {
        Shell::shouldReceive('openExternal')
            ->once()
            ->with('https://platform.openai.com/api-keys');

        $response = $this->postJson('/api/open-external', [
            'url' => 'https://platform.openai.com/api-keys',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }

    public function test_rejects_invalid_url(): void
    {
        $response = $this->postJson('/api/open-external', [
            'url' => 'not-a-valid-url',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'error' => 'Invalid URL',
            ]);
    }

    public function test_rejects_malicious_urls(): void
    {
        $maliciousUrls = [
            'javascript:alert("xss")',
            'data:text/html,<script>alert("xss")</script>',
            'not-a-url',
        ];

        foreach ($maliciousUrls as $url) {
            $response = $this->postJson('/api/open-external', [
                'url' => $url,
            ]);

            $response->assertStatus(400)
                ->assertJson([
                    'error' => 'Invalid URL',
                ]);
        }
    }

    public function test_requires_url_parameter(): void
    {
        $response = $this->postJson('/api/open-external', []);

        $response->assertStatus(400);
    }

    public function test_handles_empty_url(): void
    {
        $response = $this->postJson('/api/open-external', [
            'url' => '',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'error' => 'Invalid URL',
            ]);
    }

    public function test_handles_null_url(): void
    {
        $response = $this->postJson('/api/open-external', [
            'url' => null,
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'error' => 'Invalid URL',
            ]);
    }

    public function test_allows_https_urls(): void
    {
        $validUrls = [
            'https://github.com',
            'https://platform.openai.com',
            'https://www.example.com',
            'https://subdomain.example.com/path?query=value',
        ];

        foreach ($validUrls as $url) {
            Shell::shouldReceive('openExternal')
                ->once()
                ->with($url);

            $response = $this->postJson('/api/open-external', [
                'url' => $url,
            ]);

            $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                ]);
        }
    }

    public function test_allows_http_urls(): void
    {
        Shell::shouldReceive('openExternal')
            ->once()
            ->with('http://example.com');

        $response = $this->postJson('/api/open-external', [
            'url' => 'http://example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }

    public function test_handles_shell_exception(): void
    {
        Shell::shouldReceive('openExternal')
            ->once()
            ->with('https://github.com')
            ->andThrow(new \Exception('Shell error'));

        $response = $this->postJson('/api/open-external', [
            'url' => 'https://github.com',
        ]);

        // The route doesn't handle exceptions explicitly, so it would return 500
        // In a real implementation, you might want to catch and handle this
        $response->assertStatus(500);
    }
}

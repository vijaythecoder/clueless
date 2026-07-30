<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class TeamsMeetingUrl implements ValidationRule
{
    private const ALLOWED_HOSTS = [
        'teams.microsoft.com',
        'teams.live.com',
        'teams.microsoft.us',
        'teams.cloud.microsoft',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->isValid($value)) {
            $fail('The :attribute must be a valid Microsoft Teams meeting URL.');
        }
    }

    public function canonicalize(string $url): string
    {
        $parts = parse_url($url);

        return 'https://'
            .strtolower((string) $parts['host'])
            .$parts['path']
            .(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function isValid(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && in_array(strtolower((string) ($parts['host'] ?? '')), self::ALLOWED_HOSTS, true)
            && isset($parts['path'])
            && $parts['path'] !== ''
            && $parts['path'] !== '/'
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && (! isset($parts['port']) || $parts['port'] === 443);
    }
}

<?php

use App\Rules\TeamsMeetingUrl;
use Illuminate\Support\Facades\Validator;

it('accepts and canonicalizes supported Teams urls', function (string $url, string $canonical) {
    $rule = new TeamsMeetingUrl;
    $validator = Validator::make(['meeting_url' => $url], [
        'meeting_url' => ['required', $rule],
    ]);

    expect($validator->passes())->toBeTrue()
        ->and($rule->canonicalize($url))->toBe($canonical);
})->with([
    [
        'HTTPS://TEAMS.MICROSOFT.COM/l/meetup-join/abc?context=secret#invite',
        'https://teams.microsoft.com/l/meetup-join/abc?context=secret',
    ],
    [
        'https://teams.live.com:443/v2/meet/123?p=credential',
        'https://teams.live.com/v2/meet/123?p=credential',
    ],
    [
        'https://teams.microsoft.us/l/meetup-join/abc',
        'https://teams.microsoft.us/l/meetup-join/abc',
    ],
    [
        'https://teams.cloud.microsoft/meet/abc',
        'https://teams.cloud.microsoft/meet/abc',
    ],
]);

it('rejects http userinfo ports root paths and deceptive hosts', function (string $url) {
    $validator = Validator::make(['meeting_url' => $url], [
        'meeting_url' => ['required', new TeamsMeetingUrl],
    ]);

    expect($validator->fails())->toBeTrue();
})->with([
    'http://teams.microsoft.com/l/meetup-join/abc',
    'https://user@teams.microsoft.com/l/meetup-join/abc',
    'https://teams.microsoft.com:444/l/meetup-join/abc',
    'https://teams.microsoft.com',
    'https://teams.microsoft.com/',
    'https://teams.microsoft.com.example.test/l/meetup-join/abc',
]);

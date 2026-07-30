# Recall Teams Realtime Copilot Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a working Teams 1:1 Recall.ai capture mode that supplies finalized, named-speaker transcript turns to Clueless's existing realtime sales-copilot pipeline while preserving local capture as an explicit fallback.

**Architecture:** Laravel owns Recall credentials, bot lifecycle, signed webhook ingress, event deduplication, participant role assignment, and durable cursor replay. The NativePHP renderer starts and stops capture through local Laravel endpoints and polls normalized provider events every 250 ms. Only finalized, role-resolved transcript turns enter the existing `useCopilotSession` analysis flow; partials remain display-only.

**Tech Stack:** Laravel 12, PHP 8.2, SQLite, Pest, Vue 3 Composition API, TypeScript, Pinia, Vitest, NativePHP/Electron, Recall.ai REST API and signed webhooks.

## Global Constraints

- Preserve the existing dirty checkout. It contains the current OpenAI Realtime modernization and is the implementation base for this plan.
- Work in the current checkout because a worktree created from `HEAD` would omit required uncommitted architecture. Do not revert, reset, clean, or overwrite unrelated changes.
- Use test-driven development: add one failing behavioral test, run it and confirm the expected failure, add the minimum implementation, then rerun the focused test.
- Do not commit application changes automatically. This checkout contains broad existing work, and staging a touched file would also stage earlier changes. Use task-level diff and test checkpoints instead.
- Recall mode must never request microphone or screen-capture permission and must never start `useAudioSources`.
- The renderer must never receive the long-lived Recall API key or webhook signing secret.
- The public tunnel host must expose only the signed Recall webhook path. Every other request on that host returns `404`.
- Verify the untouched webhook request body before JSON decoding or persistence. Invalid, stale, or duplicate webhook deliveries must not create duplicate transcript turns.
- Persist and analyze finalized transcript events only. Partial transcript events may be retained in `provider_events` for cursor replay and transient UI state, but must not be persisted as authoritative `conversation_transcripts` or sent to OpenAI reasoning.
- Never infer salesperson identity from a display name. Final turns remain buffered for analysis until the user explicitly marks one human participant as `salesperson`.
- Keep participant identity separate from sales role. Provider participant IDs and display names identify speakers; `salesperson`, `customer`, `unknown`, and `bot` describe their current role in this sales call.
- Store only a SHA-256 meeting URL hash. Do not persist the raw Teams URL or raw webhook payload.
- Run every new migration against both `database/database.sqlite` and `database/nativephp.sqlite`.
- Keep controllers thin. Validation belongs in FormRequests, external API behavior in Recall services, and persistence/idempotency in domain services.
- Use `Http::` for Recall HTTP calls so failures, retries, and payloads are testable with `Http::fake()`.
- No queue worker is required for the vertical slice. Webhook work must stay bounded to signature verification, normalization, idempotent writes, and a `204` response.
- Use a database-backed analysis outbox with expiring leases. A finalized Recall turn is not considered analyzed until the renderer acknowledges its leased delivery after the OpenAI response and all resulting insights have persisted.
- Current Recall contracts used by this plan were rechecked on 2026-07-27: API authorization uses the raw key in `Authorization` (the optional `Token` prefix is also accepted), webhook secrets start with `whsec_`, realtime transcript payloads do not expose a per-utterance ID, and lifecycle status events are configured separately in the Recall dashboard.

---

## Task 1: Add Recall Configuration and Encrypted Credentials

**Files:**
- Modify: `config/services.php`
- Modify: `app/Models/SecureSetting.php`
- Create: `app/Services/Recall/RecallCredentialService.php`
- Create: `app/Http/Requests/Settings/RecallSettingsRequest.php`
- Create: `app/Http/Controllers/Settings/RecallSettingsController.php`
- Modify: `routes/settings.php`
- Modify: `resources/js/layouts/settings/Layout.vue`
- Create: `resources/js/pages/settings/Recall.vue`
- Create: `tests/Unit/Services/Recall/RecallCredentialServiceTest.php`
- Create: `tests/Feature/Controllers/Settings/RecallSettingsControllerTest.php`

- [ ] **Step 1: Write failing credential-service tests**

Test these behaviors:

```php
it('reads encrypted Recall credentials from secure settings before env fallbacks');
it('falls back to configured environment credentials');
it('reports that Recall is not configured when either secret is missing');
it('never exposes credential values from its status payload');
```

The public service contract is:

```php
final class RecallCredentialService
{
    public function apiKey(): string;
    public function webhookSecret(): string;
    public function isConfigured(): bool;

    /** @return array{configured: bool, region: string, webhook_url: string|null} */
    public function status(): array;
}
```

- [ ] **Step 2: Run the tests and confirm RED**

Run:

```bash
php artisan test tests/Unit/Services/Recall/RecallCredentialServiceTest.php
```

Expected: failure because `RecallCredentialService` does not exist.

- [ ] **Step 3: Implement credential storage and non-secret configuration**

Add these non-secret keys:

```php
'recall' => [
    'region' => env('RECALL_REGION', 'us-west-2'),
    'base_url' => env('RECALL_BASE_URL', 'https://us-west-2.recall.ai'),
    'webhook_url' => env('RECALL_WEBHOOK_URL'),
    'tunnel_host' => env('RECALL_TUNNEL_HOST'),
    'ingress_only' => (bool) env('RECALL_INGRESS_ONLY', false),
    'api_key' => env('RECALL_API_KEY'),
    'webhook_secret' => env('RECALL_WEBHOOK_SECRET'),
    'connect_timeout' => (int) env('RECALL_CONNECT_TIMEOUT', 5),
    'timeout' => (int) env('RECALL_TIMEOUT', 15),
    'webhook_tolerance_seconds' => (int) env('RECALL_WEBHOOK_TOLERANCE_SECONDS', 300),
    'webhook_max_bytes' => (int) env('RECALL_WEBHOOK_MAX_BYTES', 1048576),
    'partial_transcripts' => (bool) env('RECALL_PARTIAL_TRANSCRIPTS', false),
    'analysis_lease_seconds' => (int) env('RECALL_ANALYSIS_LEASE_SECONDS', 30),
],
```

Store `RECALL_API_KEY` and `RECALL_WEBHOOK_SECRET` in `SecureSetting`, whose encrypted cast remains the encryption boundary. Missing getters throw a domain-specific configuration exception with a safe message.

- [ ] **Step 4: Add the settings endpoint and page**

The controller accepts:

```php
[
    'api_key' => ['nullable', 'string', 'min:20'],
    'webhook_secret' => ['nullable', 'string', 'starts_with:whsec_'],
]
```

Blank values preserve existing secrets. The page receives only booleans such as `has_api_key` and `has_webhook_secret`, plus region and webhook URL. It never receives stored secret values.

- [ ] **Step 5: Run focused backend tests**

Run:

```bash
php artisan test tests/Unit/Services/Recall/RecallCredentialServiceTest.php tests/Feature/Controllers/Settings/RecallSettingsControllerTest.php
```

Expected: all tests pass and response payload assertions prove secrets are absent.

## Task 2: Create Meeting Capture Persistence

**Files:**
- Create: `app/Enums/MeetingCaptureStatus.php`
- Create: `app/Enums/MeetingProvider.php`
- Create: `app/Enums/SalesRole.php`
- Create: `app/Models/MeetingCaptureSession.php`
- Create: `app/Models/MeetingParticipant.php`
- Create: `app/Models/ProviderEvent.php`
- Create: `app/Models/MeetingAnalysisDelivery.php`
- Modify: `app/Models/ConversationSession.php`
- Modify: `app/Models/ConversationTranscript.php`
- Create: `database/migrations/2026_07_27_000001_create_meeting_capture_tables.php`
- Create: `database/migrations/2026_07_27_000002_add_provider_metadata_to_conversation_transcripts.php`
- Create: `tests/Feature/Database/MeetingCaptureMigrationsTest.php`

- [ ] **Step 1: Write a failing migration-contract test**

The test migrates fresh default and `nativephp` connections and asserts:

```php
Schema::connection($connection)->hasColumns('meeting_capture_sessions', [
    'id',
    'conversation_session_id',
    'provider',
    'provider_bot_id',
    'platform',
    'status',
    'meeting_url_hash',
    'idempotency_key',
    'failure_code',
    'failure_message',
    'started_at',
    'ended_at',
]);

Schema::connection($connection)->hasColumns('meeting_participants', [
    'meeting_capture_session_id',
    'provider_participant_id',
    'display_name',
    'email',
    'email_hash',
    'is_host',
    'is_bot',
    'sales_role',
]);

Schema::connection($connection)->hasColumns('provider_events', [
    'meeting_capture_session_id',
    'provider',
    'provider_webhook_id',
    'event_type',
    'provider_event_id',
    'meeting_participant_id',
    'normalized_payload',
    'provider_occurred_at',
    'received_at',
]);

Schema::connection($connection)->hasColumns('meeting_analysis_deliveries', [
    'meeting_capture_session_id',
    'conversation_transcript_id',
    'status',
    'lease_token',
    'leased_at',
    'attempts',
    'last_error',
    'completed_at',
]);
```

It also asserts the transcript provider columns and all unique indexes.

The second migration also adds nullable `semantic_key` to `conversation_insights` with a unique `(session_id, semantic_key)` index. Existing tool-call idempotency remains unchanged.

- [ ] **Step 2: Run the test and confirm RED**

Run:

```bash
php artisan test tests/Feature/Database/MeetingCaptureMigrationsTest.php
```

Expected: missing table failure.

- [ ] **Step 3: Implement schema, enums, casts, and relationships**

Required uniqueness:

```text
meeting_capture_sessions.provider_bot_id unique
meeting_capture_sessions.idempotency_key unique
meeting_participants(capture_id, provider_participant_id) unique
provider_events.provider_webhook_id unique
conversation_transcripts(session_id, provider, provider_item_id) unique
meeting_analysis_deliveries.conversation_transcript_id unique
conversation_insights(session_id, semantic_key) unique
```

`meeting_participants.email` uses the encrypted cast. JSON payload fields use array casts. Status and role fields use backed enums. `MeetingCaptureSession` uses UUID primary keys. Analysis delivery status is `pending`, `processing`, `completed`, or `failed`; processing leases expire after 30 seconds and may be reclaimed up to three total attempts.

- [ ] **Step 4: Run migration tests and model relationship assertions**

Run:

```bash
php artisan test tests/Feature/Database/MeetingCaptureMigrationsTest.php
```

Expected: both SQLite connections pass.

## Task 3: Implement the Recall API Client and Bot Lifecycle

**Files:**
- Create: `app/Contracts/MeetingCaptureProvider.php`
- Create: `app/Data/MeetingCaptureStartData.php`
- Create: `app/Services/Recall/RecallApiClient.php`
- Create: `app/Services/Recall/RecallCaptureProvider.php`
- Create: `app/Services/MeetingCaptureService.php`
- Create: `tests/Unit/Services/Recall/RecallApiClientTest.php`
- Create: `tests/Unit/Services/MeetingCaptureServiceTest.php`

- [ ] **Step 1: Write failing API payload and failure tests**

Assert that bot creation sends this shape:

```php
[
    'meeting_url' => $meetingUrl,
    'bot_name' => 'Clueless Copilot',
    'metadata' => [
        'clueless_capture_id' => $captureId,
        'clueless_idempotency_key' => $idempotencyKey,
    ],
    'recording_config' => [
        'transcript' => [
            'provider' => [
                'recallai_streaming' => [
                    'mode' => 'prioritize_low_latency',
                    'language_code' => 'en',
                ],
            ],
            'diarization' => [
                'use_separate_streams_when_available' => true,
            ],
        ],
        'realtime_endpoints' => [[
            'type' => 'webhook',
            'url' => config('services.recall.webhook_url'),
            'events' => [
                'participant_events.join',
                'participant_events.leave',
                'transcript.data',
            ],
        ]],
    ],
]
```

When `services.recall.partial_transcripts` is true, append `transcript.partial_data`; it is off by default. Bot lifecycle events are not valid realtime-endpoint subscriptions. They arrive through the same signed controller only after the tunnel webhook URL is also configured once in the Recall dashboard.

Also test:

```php
it('uses the configured Recall region base URL and raw authorization key');
it('retries 429 responses with bounded backoff');
it('maps 507 capacity responses to a visible capacity exception');
it('does not retry validation or authentication failures');
it('recovers an ambiguous create timeout by listing bots with its metadata idempotency key');
it('stops a bot idempotently');
```

- [ ] **Step 2: Run tests and confirm RED**

Run:

```bash
php artisan test tests/Unit/Services/Recall/RecallApiClientTest.php tests/Unit/Services/MeetingCaptureServiceTest.php
```

Expected: missing classes.

- [ ] **Step 3: Implement the provider contract**

```php
interface MeetingCaptureProvider
{
    public function provider(): MeetingProvider;

    public function start(MeetingCaptureStartData $data): array;

    public function stop(string $providerBotId): void;
}
```

`RecallApiClient` uses `Http::baseUrl(...)->withHeaders(['Authorization' => $apiKey])->acceptJson()`, a five-second connect timeout, a fifteen-second request timeout, and at most three attempts for `429` using the provider `Retry-After` delay capped at five seconds. It never logs authorization headers or response bodies containing secrets.

- [ ] **Step 4: Implement idempotent local capture-session creation**

`MeetingCaptureService::startRecall()`:

1. Validates Recall configuration.
2. Hashes the meeting URL with SHA-256.
3. Creates or returns the row for the caller-supplied idempotency key.
4. Sends the capture UUID and idempotency key in Recall bot metadata.
5. On an ambiguous timeout, lists bots by `metadata__clueless_idempotency_key` before any retry and adopts the single matching bot.
6. Stores only the returned bot ID and normalized status.
7. Marks the row failed with safe error code/message on terminal errors.

`stop()` is safe to repeat and transitions `active|waiting_room|joining|creating -> stopping -> ended`.

- [ ] **Step 5: Run focused tests**

Run:

```bash
php artisan test tests/Unit/Services/Recall/RecallApiClientTest.php tests/Unit/Services/MeetingCaptureServiceTest.php
```

Expected: payload, retry, 507, idempotency, hash-only storage, and stop tests pass.

## Task 4: Secure the Public Webhook Boundary

**Files:**
- Create: `app/Http/Middleware/RestrictRecallTunnelHost.php`
- Create: `app/Http/Middleware/LimitRecallWebhookBody.php`
- Create: `app/Services/Recall/RecallWebhookVerifier.php`
- Create: `app/Exceptions/InvalidRecallWebhook.php`
- Modify: `bootstrap/app.php`
- Create: `tests/Unit/Services/Recall/RecallWebhookVerifierTest.php`
- Create: `tests/Feature/Middleware/RestrictRecallTunnelHostTest.php`

- [ ] **Step 1: Write failing signature tests**

Use a fixed raw body and timestamp to test:

```php
it('accepts a valid webhook-id timestamp body signature');
it('accepts any valid signature from a rotated signature list');
it('rejects an altered raw body');
it('rejects a stale timestamp outside the configured tolerance');
it('rejects missing signature headers');
it('rejects a secret without the whsec prefix');
it('uses constant-time comparison');
```

The verifier contract is:

```php
public function verify(
    string $rawBody,
    string $webhookId,
    string $webhookTimestamp,
    string $webhookSignature,
): void;
```

The signed content is:

```text
{webhook-id}.{webhook-timestamp}.{raw-body}
```

Strip the required `whsec_` prefix, base64-decode the remaining key strictly, sign with HMAC-SHA256, base64-decode each space-separated `v1,<signature>` candidate strictly, and compare equal-length byte strings using `hash_equals`.

- [ ] **Step 2: Write failing tunnel-host middleware tests**

The tunnel must target a second Laravel process started in desktop development with `APP_DEBUG=false DB_CONNECTION=nativephp RECALL_INGRESS_ONLY=true`. The database selection makes the ingress process share NativePHP's active capture/settings database. The middleware reads the ingress process marker directly as a security signal so a config cache built by the normal NativePHP process cannot turn it off. In that process every request is treated as public ingress regardless of client-controlled headers. The hostname check remains defense in depth.

When ingress-only mode is active, or `Host` exactly equals configured `RECALL_TUNNEL_HOST`:

```text
POST /api/recall/webhooks -> reaches route
GET / -> 404
POST /conversations -> 404
GET /realtime-agent -> 404
```

Requests on `127.0.0.1`, `localhost`, and the NativePHP local host remain unchanged only in the normal process. Compare normalized host names without trusting `X-Forwarded-Host`; never use `Host` as the sole public-ingress signal.

The webhook route rejects `Content-Length` above 1,048,576 and also rejects a chunked/raw body whose measured byte length exceeds 1,048,576. The limit runs before signature verification and JSON decoding and returns `413` with zero writes.

- [ ] **Step 3: Run tests and confirm RED**

Run:

```bash
php artisan test tests/Unit/Services/Recall/RecallWebhookVerifierTest.php tests/Feature/Middleware/RestrictRecallTunnelHostTest.php
```

Expected: missing verifier/middleware failures.

- [ ] **Step 4: Implement verifier and global host restriction**

Register `RestrictRecallTunnelHost` before route dispatch. Return a plain `404` for every non-webhook path on the tunnel host and do not redirect. Register `routes/api.php` through `withRouting(api: ...)`; the webhook is an API route and therefore does not require a CSRF exception.

- [ ] **Step 5: Run focused security tests**

Expected: all cases pass, including an assertion that invalid requests create zero database rows.

## Task 5: Normalize and Persist Recall Webhook Events

**Files:**
- Create: `app/Data/NormalizedProviderEvent.php`
- Create: `app/Services/Recall/RecallEventNormalizer.php`
- Create: `app/Services/Recall/RecallWebhookService.php`
- Create: `app/Http/Controllers/RecallWebhookController.php`
- Create: `routes/api.php`
- Modify: `bootstrap/app.php`
- Create: `tests/Unit/Services/Recall/RecallEventNormalizerTest.php`
- Create: `tests/Feature/Controllers/RecallWebhookControllerTest.php`

- [ ] **Step 1: Add sanitized Recall fixtures**

Create final, partial, participant join, participant leave, bot status, duplicate, and out-of-order fixtures under:

```text
tests/Fixtures/Recall/
```

Fixtures contain no real meeting URLs, emails, credentials, or customer data.

- [ ] **Step 2: Write failing normalizer tests**

Normalized event shape:

```php
[
    'event_type' => 'transcript.final',
    'provider_event_id' => 'provider-event-id',
    'provider_item_id' => 'recall_'.hash('sha256', 'bot|participant|start|end|text'),
    'utterance_key' => 'participant-id:1200',
    'provider_participant_id' => 'participant-id',
    'display_name' => 'Ada Lovelace',
    'is_host' => false,
    'is_bot' => false,
    'text' => 'Final transcript text',
    'start_offset_ms' => 1200,
    'end_offset_ms' => 2860,
    'occurred_at' => '2026-07-27T12:00:02.860Z',
]
```

Unknown event types return `null` and produce no persisted provider event.

Dashboard bot status webhooks map as follows:

```text
bot.joining_call -> joining
bot.in_waiting_room -> waiting_room
bot.in_call_not_recording -> joining
bot.recording_permission_allowed -> joining
bot.in_call_recording -> active
bot.call_ended -> ended
bot.done -> ended
bot.recording_permission_denied -> failed
bot.fatal -> failed
```

Unknown future bot codes are retained as diagnostic provider events without changing the current capture state.

- [ ] **Step 3: Write failing controller tests**

Assert:

```php
it('verifies the untouched raw body before decoding');
it('returns 204 after persisting a valid normalized event');
it('deduplicates repeated webhook IDs');
it('upserts participants by capture and provider participant ID');
it('persists partials as cursor events but not conversation transcripts');
it('persists finals once using provider item id');
it('derives the same final provider item id for semantically identical retry payloads with different webhook ids');
it('ignores a late partial after the matching utterance is final');
it('ignores an empty final with a diagnostic counter and a 204 response');
it('returns 204 for a valid event whose bot is already ended');
it('maps dashboard bot lifecycle webhooks to capture states');
it('returns 401 and writes nothing for an invalid signature');
it('returns 404 and writes nothing for an unknown bot id');
```

- [ ] **Step 4: Run tests and confirm RED**

Run:

```bash
php artisan test tests/Unit/Services/Recall/RecallEventNormalizerTest.php tests/Feature/Controllers/RecallWebhookControllerTest.php
```

- [ ] **Step 5: Implement one-transaction webhook persistence**

Within one database transaction:

1. Resolve the capture session from the provider bot ID.
2. `firstOrCreate` the provider event by `provider_webhook_id`.
3. Return immediately when the webhook ID already exists.
4. Upsert participant identity.
5. Persist normalized event data only.
6. For final transcripts, `firstOrCreate` the conversation transcript by `(conversation_session_id, provider, provider_item_id)`.
7. Create one pending `meeting_analysis_deliveries` row for a newly created final transcript.
8. Store the participant foreign key and current role, which may still be `unknown`.

Do not call OpenAI, broadcast, enqueue, or retain the raw body.

Recall's current realtime payload exposes a transcript artifact ID but no utterance ID. Derive the provider item ID deterministically from bot ID, participant ID, start offset, end offset, and normalized final text. Use `participant_id:start_offset_ms` as the replaceable partial `utterance_key`; use the deterministic final ID as durable evidence.

- [ ] **Step 6: Run focused tests**

Expected: all signature, dedupe, partial/final, participant, and unknown-bot cases pass.

## Task 6: Add Capture Control, Cursor Feed, and Speaker Assignment APIs

**Files:**
- Create: `app/Http/Requests/StartMeetingCaptureRequest.php`
- Create: `app/Http/Requests/AssignMeetingParticipantRequest.php`
- Create: `app/Http/Requests/AcknowledgeMeetingAnalysisRequest.php`
- Create: `app/Rules/TeamsMeetingUrl.php`
- Create: `app/Http/Controllers/MeetingCaptureController.php`
- Create: `app/Http/Controllers/MeetingEventController.php`
- Create: `app/Http/Controllers/MeetingParticipantController.php`
- Create: `app/Http/Controllers/MeetingAnalysisController.php`
- Create: `app/Services/MeetingEventFeed.php`
- Create: `app/Services/MeetingAnalysisDeliveryService.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/Controllers/MeetingCaptureControllerTest.php`
- Create: `tests/Feature/Controllers/MeetingEventControllerTest.php`
- Create: `tests/Feature/Controllers/MeetingParticipantControllerTest.php`
- Create: `tests/Feature/Controllers/MeetingAnalysisControllerTest.php`
- Create: `tests/Unit/Rules/TeamsMeetingUrlTest.php`

- [ ] **Step 1: Write failing start/stop API tests**

Start request:

```php
[
    'provider' => ['required', Rule::enum(MeetingProvider::class)],
    'meeting_url' => ['required_if:provider,recall', 'url', 'max:2048', new TeamsMeetingUrl],
    'idempotency_key' => ['required', 'uuid'],
]
```

Response includes capture UUID, conversation UUID, safe status, and participant/event URLs. It never echoes the meeting URL or Recall bot ID.

`TeamsMeetingUrl` accepts HTTPS URLs only, lowercases the host, strips the fragment for validation/hashing, and allows exactly:

```text
teams.microsoft.com
teams.live.com
teams.microsoft.us
teams.cloud.microsoft
```

The path must be non-empty. Query parameters are preserved because they may contain meeting credentials.

- [ ] **Step 2: Write failing cursor-feed tests**

Contract:

```http
GET /meeting-captures/{capture}/events?after=123&limit=100
```

Response:

```json
{
  "events": [],
  "next_cursor": 123,
  "has_more": false,
  "capture": {
    "id": "uuid",
    "status": "active",
    "failure_code": null,
    "failure_message": null
  }
}
```

Events are strictly ordered by `provider_events.id`, never skipped, and replay safely after renderer refresh. Clamp `limit` to `1..200`.

- [ ] **Step 3: Write failing explicit role-assignment tests**

`PATCH /meeting-captures/{capture}/participants/{participant}` with:

```json
{"sales_role":"salesperson"}
```

must atomically:

1. Set the chosen human participant to `salesperson`.
2. Set other non-bot humans to `customer`.
3. Leave bots as `bot`.
4. Update prior Recall transcript rows for each participant to the assigned speaker role.
5. Return the full participant list.

Reject assigning bot participants and participants from another capture.

- [ ] **Step 4: Write failing analysis claim/ack tests**

Contracts:

```http
POST /meeting-captures/{capture}/analysis-deliveries/claim
POST /meeting-captures/{capture}/analysis-deliveries/{delivery}/ack
```

Claim returns up to ten chronological, finalized, role-resolved turns and atomically marks each `processing` with a random lease token. Ordering uses provider start offset, then provider event cursor. Pending unknown-role turns are not claimable. A processing row is reclaimable after 30 seconds; its fourth claim attempt becomes terminal `failed`.

Ack requires the matching lease token and accepts `completed` or `failed` plus a nullable sanitized error code. A repeated completed ack is idempotent. A stale or foreign token returns `409`.

- [ ] **Step 5: Run tests and confirm RED**

Run:

```bash
php artisan test tests/Feature/Controllers/MeetingCaptureControllerTest.php tests/Feature/Controllers/MeetingEventControllerTest.php tests/Feature/Controllers/MeetingParticipantControllerTest.php tests/Feature/Controllers/MeetingAnalysisControllerTest.php tests/Unit/Rules/TeamsMeetingUrlTest.php
```

- [ ] **Step 6: Implement thin controllers, feed service, and analysis leases**

The event feed serializes participant identity and current sales role but never returns encrypted email unless the local UI explicitly needs it. This vertical slice returns `email_present: bool`, not email text.

- [ ] **Step 7: Run focused tests**

Expected: idempotent start/stop, cursor replay, cross-capture rejection, and backfilled role tests pass.

## Task 7: Add Frontend Recall Contracts and Cursor Polling

**Files:**
- Create: `resources/js/types/meetingCapture.ts`
- Create: `resources/js/services/meetingEventNormalizer.ts`
- Create: `resources/js/composables/useRecallMeetingSession.ts`
- Create: `resources/js/services/__tests__/meetingEventNormalizer.test.ts`
- Create: `resources/js/composables/__tests__/useRecallMeetingSession.test.ts`

- [ ] **Step 1: Write failing normalizer tests**

Frontend normalized event union:

```ts
export type MeetingEvent =
    | { type: 'capture.status'; cursor: number; status: MeetingCaptureStatus; failure?: CaptureFailure }
    | { type: 'participant.upsert'; cursor: number; participant: MeetingParticipant }
    | { type: 'transcript.partial'; cursor: number; turn: RecallTranscriptTurn }
    | { type: 'transcript.final'; cursor: number; turn: RecallTranscriptTurn };
```

Tests reject malformed events, blank transcript text, missing participant IDs, and regressing cursors.

- [ ] **Step 2: Write failing composable tests with fake timers**

Assert:

```ts
it('polls every 250ms while capture is non-terminal');
it('drains every page when has_more is true before waiting');
it('resumes from the last durable cursor after a transient error');
it('deduplicates events replayed after renderer refresh');
it('keeps partials display-only');
it('buffers finalized unknown-role turns for analysis');
it('claims buffered turns in provider chronology after salesperson assignment');
it('acknowledges a delivery only after analysis and insight persistence complete');
it('reclaims an expired analysis lease after renderer restart');
it('stops polling after ended or failed');
it('stops a bot idempotently and drains the final event page');
```

- [ ] **Step 3: Run tests and confirm RED**

Run:

```bash
npm run test:frontend -- resources/js/services/__tests__/meetingEventNormalizer.test.ts resources/js/composables/__tests__/useRecallMeetingSession.test.ts
```

- [ ] **Step 4: Implement the composable**

Public contract:

```ts
export function useRecallMeetingSession(options: {
    onPartial: (turn: RecallTranscriptTurn) => void;
    onFinal: (turn: RecallTranscriptTurn) => Promise<void>;
    onParticipants: (participants: MeetingParticipant[]) => void;
    onStatus: (status: MeetingCaptureStatus, failure?: CaptureFailure) => void;
}) {
    return {
        capture,
        participants,
        isPolling,
        start,
        stop,
        assignSalesperson,
        claimAnalysisDeliveries,
        acknowledgeAnalysisDelivery,
        resume,
    };
}
```

Use an `AbortController`, one in-flight poll at a time, and a `Set<number>` bounded to the active capture for replay dedupe. Never busy-loop when `has_more` is false. The cursor feed drives display; the leased analysis endpoint, not in-memory event buffering, drives the durable copilot handoff.

- [ ] **Step 5: Run focused frontend tests**

Expected: all polling, replay, buffering, and shutdown tests pass.

## Task 8: Generalize Transcript State from Two Audio Streams to Named Participants

**Files:**
- Modify: `resources/js/types/realtimeAgent.ts`
- Modify: `resources/js/stores/realtimeAgent.ts`
- Modify: `resources/js/stores/__tests__/realtimeAgent.test.ts`
- Modify: `resources/js/components/RealtimeAgent/Content/LiveTranscription.vue`

- [ ] **Step 1: Write failing Pinia tests**

Add participant-aware turns while preserving local roles:

```ts
export type SalesRole = 'salesperson' | 'customer' | 'unknown' | 'bot';

export interface TranscriptGroup {
    id: string;
    role: SalesRole | 'system';
    participantId?: string;
    displayName?: string;
    sourceStream: 'salesperson' | 'customer' | 'copilot' | 'recall';
    content: string;
    isFinal: boolean;
    cursor?: number;
    timestamp: string;
}
```

Test:

```ts
it('upserts Recall partials by participant and provider item id');
it('replaces a partial with exactly one final turn');
it('does not merge simultaneous turns from different participants');
it('updates earlier transcript roles after explicit assignment');
it('keeps local You and Customer labels unchanged');
```

- [ ] **Step 2: Run the store tests and confirm RED**

Run:

```bash
npm run test:frontend -- resources/js/stores/__tests__/realtimeAgent.test.ts
```

- [ ] **Step 3: Implement participant-aware store mutations**

Key transcript identity is provider item ID, not text or timestamp. For Recall, render `displayName` as the primary label and a quiet `You` indicator only when `role === 'salesperson'`.

- [ ] **Step 4: Run focused tests and typecheck**

Run:

```bash
npm run test:frontend -- resources/js/stores/__tests__/realtimeAgent.test.ts
npm run typecheck
```

Expected: existing local-mode tests and new named-participant tests pass.

## Task 9: Build Capture Mode and Participant Assignment UI

**Files:**
- Create: `resources/js/components/RealtimeAgent/Modals/MeetingCaptureModal.vue`
- Create: `resources/js/components/RealtimeAgent/Modals/ParticipantRoleModal.vue`
- Modify: `resources/js/pages/RealtimeAgent/Copilot.vue`
- Modify: `resources/js/components/RealtimeAgent/Navigation/TitleBar.vue`
- Create: `resources/js/components/RealtimeAgent/Modals/__tests__/MeetingCaptureModal.test.ts`
- Create: `resources/js/pages/RealtimeAgent/__tests__/CopilotRecallMode.test.ts`

- [ ] **Step 1: Write failing UI behavior tests**

Assert:

```ts
it('starts in an explicit Local or Teams via Recall mode');
it('requires a Teams URL before starting Recall');
it('never calls microphone or system audio start methods in Recall mode');
it('shows creating joining waiting room active stopping ended and failed states');
it('opens Recall settings when credentials are missing');
it('shows This is me after two human participants appear');
it('does not analyze final turns before salesperson assignment');
it('uses the selected speaker names for future and buffered turns');
it('offers Local fallback after a Recall 507 without starting it automatically');
```

- [ ] **Step 2: Run tests and confirm RED**

Run:

```bash
npm run test:frontend -- resources/js/components/RealtimeAgent/Modals/__tests__/MeetingCaptureModal.test.ts resources/js/pages/RealtimeAgent/__tests__/CopilotRecallMode.test.ts
```

- [ ] **Step 3: Implement mode-specific startup**

Refactor `startSession` into:

```ts
async function startLocalSession(): Promise<void>;
async function startRecallSession(meetingUrl: string): Promise<void>;
```

Only `startLocalSession` connects the two OpenAI transcription sessions and starts microphone/system audio. Both modes connect the text-only copilot and create a conversation session.

- [ ] **Step 4: Implement participant assignment**

When two or more non-bot participants exist and none is assigned salesperson, show a compact modal listing speaker names with one `This is me` action per row. Do not use a name heuristic.

- [ ] **Step 5: Implement deterministic shutdown**

Recall end-call order:

1. Disable new UI actions.
2. Stop the Recall bot.
3. Drain event pages until the capture is terminal and no new event arrives during a one-second quiet period, with a ten-second upper bound.
4. Claim and process all role-resolved pending analysis deliveries.
5. Wait until no pending or processing analysis delivery remains and all persistence promises settle.
6. End the conversation session.
7. Disconnect the copilot and clear capture state.

- [ ] **Step 6: Run focused UI tests**

Expected: Recall mode requests no local permissions and local mode remains unchanged.

## Task 10: Add Pain Point and Discussion Topic Copilot Tools

**Files:**
- Modify: `app/Services/SalesToolRegistry.php`
- Modify: `app/Services/CopilotSessionService.php`
- Modify: `tests/Unit/Services/SalesToolRegistryTest.php`
- Modify: `tests/Unit/Services/CopilotSessionServiceTest.php`
- Modify: `resources/js/types/realtimeAgent.ts`
- Modify: `resources/js/stores/realtimeAgent.ts`
- Modify: `resources/js/stores/__tests__/realtimeAgent.test.ts`
- Modify: `resources/js/pages/RealtimeAgent/Copilot.vue`
- Modify: `resources/js/composables/useCopilotSession.ts`
- Modify: `resources/js/composables/__tests__/useCopilotSession.test.ts`

- [ ] **Step 1: Write failing backend tool-schema tests**

Add:

```text
capture_pain_point
  text: non-empty string
  category: optional string
  evidence_item_ids: non-empty array of provider transcript item IDs
  severity: high|medium|low

capture_discussion_topic
  name: non-empty normalized topic name
  sentiment: positive|negative|neutral|mixed
  context: non-empty short supporting context
  evidence_item_ids: non-empty array of provider transcript item IDs
```

Tool instructions require evidence IDs from the supplied finalized turns and forbid inventing IDs.

- [ ] **Step 2: Write failing frontend tool and analysis-queue tests**

Assert:

```ts
it('stores pain points idempotently by tool call id and semantic analysis key');
it('stores discussion topics idempotently by tool call id and semantic analysis key');
it('persists both card types as conversation insights');
it('rejects evidence ids that are not finalized transcripts in the same capture');
it('includes participant name role and provider item id in analysis input');
it('does not silently drop pending finalized turns when the queue is full');
it('flushPending resolves only after every accepted turn has completed or failed visibly');
```

- [ ] **Step 3: Run tests and confirm RED**

Run:

```bash
php artisan test tests/Unit/Services/SalesToolRegistryTest.php tests/Unit/Services/CopilotSessionServiceTest.php
npm run test:frontend -- resources/js/stores/__tests__/realtimeAgent.test.ts resources/js/composables/__tests__/useCopilotSession.test.ts
```

- [ ] **Step 4: Implement structured tools and lossless backpressure**

Extend `AnalysisTurn`:

```ts
interface AnalysisTurn {
    itemId: string;
    participantId?: string;
    speakerName: string;
    role: 'salesperson' | 'customer';
    transcript: string;
    context: string;
    attempts: number;
}
```

Replace silent `pendingTurns.shift()` eviction with a bounded, visible backpressure state. Accepted local finalized turns remain queued; if the local limit is reached, mark analysis as delayed. Recall turns come from leased analysis deliveries and are acknowledged only after the response and its async UI-tool persistence promises settle. Store the tool call ID as the primary insight idempotency key and a secondary semantic key derived from analysis delivery ID, card type, sorted evidence IDs, and normalized content to survive lease replay with new OpenAI tool call IDs.

- [ ] **Step 5: Run focused backend and frontend tests**

Expected: schemas, prompt instructions, card persistence, evidence IDs, and no-drop queue tests pass.

## Task 11: Harden Error Recovery, Resume, and History

**Files:**
- Modify: `app/Services/ConversationPersistenceService.php`
- Modify: `app/Http/Requests/PersistTranscriptsRequest.php`
- Modify: `app/Http/Controllers/ConversationController.php`
- Modify: `tests/Feature/Controllers/ConversationControllerTest.php`
- Modify: `resources/js/pages/Conversations/Show.vue`
- Modify: `resources/js/pages/RealtimeAgent/Copilot.vue`
- Create: `tests/Feature/RecallEndToEndPersistenceTest.php`

- [ ] **Step 1: Write failing persistence regression tests**

Assert:

```php
it('accepts named Recall participants with provider evidence metadata');
it('ignores blank Recall final transcript text while retaining a diagnostic counter');
it('upserts concurrent duplicate provider item ids without 500 errors');
it('returns persisted named turns and Recall insights in history');
it('ends a capture and conversation idempotently');
```

- [ ] **Step 2: Run tests and confirm RED**

Run:

```bash
php artisan test tests/Feature/Controllers/ConversationControllerTest.php tests/Feature/RecallEndToEndPersistenceTest.php
```

- [ ] **Step 3: Implement provider-aware persistence**

Do not allocate `order_index` with an unprotected `max()+1` query for Recall events. Use the monotonic provider-event cursor as the Recall order index, retaining the current local ordering path for OpenAI streams.

Return safe failure codes to the renderer:

```text
recall_not_configured
recall_invalid_meeting_url
recall_rate_limited
recall_capacity_unavailable
recall_authentication_failed
recall_webhook_timeout
recall_bot_failed
```

Raw upstream bodies and stack traces stay in local logs with secret redaction and never appear in UI responses.

- [ ] **Step 4: Run persistence and history tests**

Expected: duplicate delivery and repeated end-call requests are idempotent.

## Task 12: Full Verification and Live Teams Acceptance

**Files:**
- Modify as needed: `README.md`
- Create: `docs/recall-teams-setup.md`
- Create: `docs/superpowers/verification/2026-07-27-recall-teams-realtime-copilot.md`

- [ ] **Step 1: Format only implementation files**

Run Pint with an explicit list of changed PHP files and Prettier with an explicit list of changed Vue/TypeScript files. Do not run broad formatters over unrelated dirty files.

- [ ] **Step 2: Document the required Recall dashboard setup**

`docs/recall-teams-setup.md` must include:

1. Create a Recall API key and workspace verification secret in the same configured region.
2. Save both through Clueless Recall settings.
3. Configure a stable HTTPS tunnel hostname that forwards to local Laravel.
4. Start a dedicated desktop-development ingress process with `APP_DEBUG=false DB_CONNECTION=nativephp RECALL_INGRESS_ONLY=true php artisan serve --host=127.0.0.1 --port=8101` so it shares NativePHP's active SQLite database while exposing only the signed webhook route.
5. Point the tunnel to port 8101, never to the NativePHP/local application port.
6. Set `RECALL_WEBHOOK_URL=https://<host>/api/recall/webhooks` and `RECALL_TUNNEL_HOST=<host>` for the normal application process.
7. Add that exact URL once in Recall's dashboard Webhooks tab for bot lifecycle events.
8. Keep `RECALL_PARTIAL_TRANSCRIPTS=false` for the first live gate; enable it only after final-turn behavior passes.
9. Restart Laravel after environment changes and verify the ingress-process deny-by-default checks before creating a bot.

- [ ] **Step 3: Run all automated verification**

Run:

```bash
php artisan test
npm run test:frontend
npm run typecheck
npm run lint:check
npm run build
php artisan migrate --force
php artisan migrate --database=nativephp --force
php artisan migrate:status
php artisan migrate:status --database=nativephp
```

Expected: all tests, type checks, lint checks, build, and both migration statuses pass.

- [ ] **Step 4: Verify the public-host security boundary**

With the app and approved tunnel running:

```bash
curl -i https://$RECALL_TUNNEL_HOST/
curl -i https://$RECALL_TUNNEL_HOST/realtime-agent
curl -i -X POST https://$RECALL_TUNNEL_HOST/api/recall/webhooks
```

Expected:

```text
GET routes -> 404
unsigned webhook -> 401
valid signed Recall delivery -> 204
```

- [ ] **Step 5: Run the live Teams 1:1 gate**

Record evidence for every item:

- Bot is created once, appears as `Clueless Copilot`, enters the Teams lobby, and joins after manual admission.
- Two human participant names appear and the salesperson explicitly selects `This is me`.
- No microphone or screen permission is requested in Recall mode.
- Fifty finalized turns, including ten overlapping exchanges, contain no duplicate turns and no role swaps.
- Renderer refresh replays from the cursor without duplication or loss.
- Final transcript latency p95 is at most four seconds from provider event occurrence to UI display.
- Sales card latency p95 is at most seven seconds from final transcript occurrence to card display.
- A thirty-minute meeting has no missing finalized utterances.
- Pain points and discussion topics include valid provider transcript evidence IDs.
- End Call removes the bot, drains final events, persists transcripts/insights, and reaches `ended`.
- Call history shows named speakers, commitments, pain points, discussion topics, and follow-ups.
- A separate local capture session still requests the expected local permissions and works.

- [ ] **Step 6: Write the verification report**

The report separates:

```text
Automated checks: command, exit status, result
Live checks: timestamped observation and measured latency
Not run: explicit reason
Residual risks: only evidence-backed remaining risks
```

Do not call the feature production-ready unless every live gate above passes.

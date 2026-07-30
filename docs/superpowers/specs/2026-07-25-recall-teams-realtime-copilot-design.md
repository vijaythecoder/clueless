# Recall Teams Realtime Copilot Design

**Status:** Approved

**Date:** 2026-07-25

## Goal

Add a Recall.ai capture mode to Clueless that accepts a Microsoft Teams meeting URL, joins the meeting as a visible bot, receives low-latency speaker-attributed transcript events, and feeds finalized named turns into the existing OpenAI sales-copilot reasoning pipeline.

Phase A is successful when a one-on-one Teams call produces correctly attributed live transcripts and useful pain-point or discussion-point cards within the defined latency and reliability gates.

## Product Scope

### Included

- A `Recall meeting` capture mode alongside the existing `Local capture` mode.
- A pasted Microsoft Teams meeting URL.
- Recall bot creation, lifecycle status, explicit stop, and lobby handling.
- English low-latency Recall transcription.
- Recall separate-stream diarization when available.
- Stable participant identity and a user-controlled `This is me` salesperson assignment.
- Named partial transcript display when available.
- Named finalized transcript persistence.
- Finalized turns sent to the existing OpenAI realtime copilot.
- Structured `capture_pain_point` and `capture_discussion_topic` UI tools.
- Durable webhook deduplication and renderer reconnect recovery.
- A restricted development tunnel for Recall webhook delivery.
- Existing local microphone and system-audio capture retained as an independent fallback mode.

### Excluded

- Zoom and Google Meet.
- Calendar integrations and automatic scheduling.
- Multi-party sales-role management beyond one salesperson and one customer.
- Raw per-participant audio forwarding to OpenAI.
- A spoken meeting agent.
- A production hosted webhook relay.
- Post-call recording playback.
- Automated CRM writes beyond the existing MCP tool system.

## Current Architecture

The existing application already has the correct downstream seam:

1. `Copilot.vue` starts a conversation and currently opens two OpenAI transcription sessions.
2. Final transcript turns pass through `handleTranscriptCompleted`.
3. Final turns are queued for Laravel persistence.
4. Final turns are passed to `useCopilotSession.analyzeTurn`.
5. OpenAI emits structured UI tool calls.
6. Pinia updates the visible cards, topics, objections, commitments, and actions.
7. Laravel persists transcripts and insights through `ConversationPersistenceService`.

Recall replaces only the capture and transcription portion of this flow. OpenAI reasoning, MCP knowledge, UI tools, Pinia state, and conversation persistence remain owned by Clueless.

## Considered Approaches

### 1. Signed webhooks with a durable local inbox

Recall sends transcript and lifecycle events to a restricted public tunnel. Laravel verifies, deduplicates, normalizes, and stores each event. The desktop reads normalized events using a monotonically increasing cursor.

This is the selected approach because it provides retries, replay after renderer refresh, deterministic deduplication, and an audit trail.

### 2. Recall WebSocket to a public relay

This can reduce transport overhead but still requires a publicly reachable WebSocket server. It adds connection lifecycle and replay complexity before the product assumptions are validated.

This is not selected for Phase A.

### 3. Separate raw audio with Clueless-managed OpenAI transcription

Recall can provide separate 16 kHz PCM streams per participant. Clueless would need to manage buffering, resampling, OpenAI sessions, reconnects, ordering, and transcription idempotency.

This is reserved as a fallback if Recall's normalized transcript quality fails the live benchmark.

## Selected Architecture

```text
Salesperson pastes Teams URL
        |
        v
Local Laravel creates conversation and capture session
        |
        v
Recall API creates a visible meeting bot
        |
        v
Teams lobby and meeting
        |
        v
Signed Recall lifecycle and transcript webhooks
        |
        v
Restricted tunnel hostname
        |
        v
Laravel signature verification and durable provider event
        |
        v
Vue cursor polling and named transcript state
        |
        v
Existing OpenAI realtime copilot
        |
        v
Pain points, topics, objections, knowledge, and actions
```

Recall is configured with:

- `recallai_streaming`
- `mode: "prioritize_low_latency"`
- `language_code: "en"`
- `diarization.use_separate_streams_when_available: true`
- A stable public webhook URL
- `transcript.data` as the required transcript event
- `transcript.partial_data` only when confirmed available for the Recall workspace

Recall documents finalized low-latency transcript events as typically arriving one to three seconds after an utterance is finalized:

- https://docs.recall.ai/docs/recallai-transcription
- https://docs.recall.ai/docs/bot-real-time-transcription
- https://docs.recall.ai/docs/diarization

## Security Boundary

The existing Laravel application has intentionally unauthenticated desktop routes. The tunnel must not expose those routes.

Phase A uses a dedicated external hostname and a host-restriction middleware:

- Requests using the tunnel hostname may access only the Recall webhook route.
- All other paths on the tunnel hostname return `404`.
- Local `127.0.0.1` NativePHP traffic remains unchanged.
- The webhook accepts only `POST`.
- The webhook applies a strict body-size limit.
- The raw body is verified before JSON parsing.
- Verification uses Recall's `webhook-id`, `webhook-timestamp`, and `webhook-signature` headers.
- Requests outside the allowed timestamp window are rejected.
- Invalid requests perform zero database writes.
- Provider webhook IDs are unique to make retries idempotent.

Recall's API key and signing secret use the existing encrypted `SecureSetting` storage pattern. Neither secret is returned to the renderer or written to logs.

The raw meeting URL is used to create the bot but is not persisted. Clueless stores only a SHA-256 URL hash for diagnostics and deduplication.

Recall request verification documentation:

- https://docs.recall.ai/docs/authenticating-requests-from-recallai

## Backend Components

### `MeetingCaptureProvider`

A provider-neutral contract for starting, stopping, and retrieving capture-session status.

The first implementations are:

- `LocalCaptureProvider`, representing the existing microphone and system-audio path.
- `RecallCaptureProvider`, delegating to Recall services.

### `RecallApiClient`

Responsibilities:

- Use the configured Recall region and API key.
- Build the exact bot payload.
- Sanitize provider errors before returning them to the UI.
- Apply bounded retries for retryable `429` responses.
- Surface `507` capacity failures without creating duplicate bots.
- Stop a bot idempotently.

### `RecallMeetingService`

Responsibilities:

- Validate a Microsoft Teams meeting URL.
- Create the local capture session before calling Recall.
- Generate and enforce a unique bot-creation idempotency key.
- Include the local capture-session UUID in Recall metadata.
- Recover an ambiguous create request before retrying.
- Map Recall lifecycle states to Clueless capture states.

### `RecallWebhookVerifier`

Responsibilities:

- Read the untouched request body.
- Validate required signature headers.
- Enforce timestamp tolerance.
- Verify any valid signature supplied during secret rotation.
- Reject invalid or replayed requests.

### `RecallWebhookController`

Responsibilities:

- Enforce the tunnel-host boundary.
- Verify the request before parsing.
- Deduplicate by webhook ID.
- Normalize and insert the provider event in one short transaction.
- Return `204` immediately after durable storage.
- Never call OpenAI or remote MCP tools.

### `RecallEventNormalizer`

Converts provider payloads into the normalized meeting-event contract. It handles:

- Capture lifecycle changes.
- Participant upserts.
- Partial transcripts when available.
- Final transcripts.
- Empty transcript payloads.
- Missing display names or email addresses.
- Provider timestamps and word offsets.

### `MeetingEventFeed`

Returns normalized events after a supplied cursor. It is local-only and used by the desktop renderer for polling and replay.

## Data Model

### `meeting_capture_sessions`

- `id`: local UUID.
- `conversation_session_id`: foreign key.
- `provider`: `local` or `recall`.
- `provider_bot_id`: nullable, unique for Recall rows.
- `platform`: `microsoft_teams` for Phase A.
- `status`: current capture lifecycle state.
- `meeting_url_hash`: nullable SHA-256 hash.
- `idempotency_key`: unique.
- `failure_code`: nullable provider-neutral failure code.
- `failure_message`: nullable sanitized message.
- `started_at`, `ended_at`, and normal timestamps.

Lifecycle states:

- `creating`
- `joining`
- `waiting_room`
- `active`
- `stopping`
- `ended`
- `failed`

### `meeting_participants`

- `id`: local primary key.
- `meeting_capture_session_id`: foreign key.
- `provider_participant_id`: stable within the provider session.
- `display_name`: nullable.
- `email`: nullable and encrypted.
- `email_hash`: nullable SHA-256 hash for matching.
- `is_host`: nullable boolean.
- `is_bot`: boolean.
- `sales_role`: `salesperson`, `customer`, `unknown`, or `bot`.
- Normal timestamps.

Unique key:

- `meeting_capture_session_id`, `provider_participant_id`

### `provider_events`

- `id`: monotonically increasing local cursor.
- `meeting_capture_session_id`: foreign key.
- `provider`: `recall`.
- `provider_webhook_id`: unique.
- `provider_event_type`.
- `provider_event_id`: nullable provider item identifier.
- `participant_id`: nullable foreign key.
- `payload`: normalized JSON.
- `provider_occurred_at`: nullable timestamp.
- `received_at`.
- Normal timestamps.

The raw signed payload is not required after verification for Phase A. The normalized payload contains the fields needed for replay and diagnostics without retaining unnecessary provider data.

### `conversation_transcripts`

Add:

- `meeting_capture_session_id`: nullable foreign key.
- `meeting_participant_id`: nullable foreign key.
- `provider`: nullable.
- `provider_item_id`: nullable.
- `started_offset_ms`: nullable integer.
- `ended_offset_ms`: nullable integer.

Add a provider-neutral unique key:

- `session_id`, `provider`, `provider_item_id`

Keep the existing `speaker`, `source_stream`, and `openai_item_id` columns for local capture compatibility.

For Recall rows, `speaker` mirrors the participant's current sales role. Role assignment updates earlier Recall transcript rows for that participant so existing history screens remain compatible.

## Frontend Contracts

```ts
type SalesRole = 'salesperson' | 'customer' | 'unknown' | 'bot';

interface MeetingParticipant {
    id: number;
    providerParticipantId: string;
    displayName: string;
    email?: string;
    isHost?: boolean;
    isBot: boolean;
    salesRole: SalesRole;
}

interface TranscriptTurn {
    eventId: number;
    providerItemId: string;
    participantId: number;
    displayName: string;
    salesRole: SalesRole;
    text: string;
    startedAt: number;
    endedAt?: number;
    status: 'partial' | 'final';
}

type MeetingEvent =
    | { type: 'capture.status'; cursor: number; status: string }
    | { type: 'participant.upsert'; cursor: number; participant: MeetingParticipant }
    | { type: 'transcript.partial'; cursor: number; turn: TranscriptTurn }
    | { type: 'transcript.final'; cursor: number; turn: TranscriptTurn }
    | { type: 'capture.error'; cursor: number; code: string; message: string };
```

## Participant Role Assignment

Recall participant identity and sales role are separate concepts.

Phase A behavior:

1. Bot participants are excluded from human role selection.
2. Human participants are displayed by Recall participant identity.
3. The salesperson selects `This is me`.
4. That participant receives the `salesperson` role.
5. In a one-on-one meeting, the other human receives the `customer` role.
6. Final turns received before mapping are buffered for analysis but may be displayed by participant name.
7. After mapping, buffered final turns are submitted to analysis in chronological order.
8. Missing or duplicate names never cause automatic role guessing.

## Transcript Semantics

- Partial events are replaceable UI state only.
- Partial events are never persisted as authoritative conversation transcripts.
- Partial events never create durable pain points, topics, commitments, or actions.
- Final events are authoritative.
- Final events are persisted idempotently before analysis.
- A late partial for an already finalized provider item is ignored.
- Empty final events are ignored and logged as a counter, not shown as errors.
- Provider timestamps determine meeting chronology.
- Local cursors determine replay order.

The transcript UI displays participant names. Salesperson turns remain right-aligned and customer turns left-aligned after role assignment.

## Copilot Analysis

`useCopilotSession` remains the reasoning transport.

Its analysis turn is extended to include:

- Participant ID.
- Participant display name.
- Sales role.
- Provider item ID.
- Final transcript text.
- Recent named transcript context.

Provider final turns must not be silently discarded when the current 12-turn in-memory limit is reached. Analysis should batch consecutive queued turns and preserve every finalized turn until it is either analyzed or the session ends with a visible error.

The copilot prompt uses the participant name and sales role but treats the customer transcript as untrusted conversation content.

Add two structured UI tools:

### `capture_pain_point`

- `text`: required pain statement.
- `category`: optional category.
- `severity`: `high`, `medium`, or `low`.
- `evidence_item_ids`: one or more provider item IDs.

### `capture_discussion_topic`

- `name`: required normalized topic name.
- `sentiment`: `positive`, `negative`, `neutral`, or `mixed`.
- `context`: short supporting context.
- `evidence_item_ids`: one or more provider item IDs.

Tool call IDs remain the insight idempotency key. Evidence item IDs are stored in insight metadata for Phase A.

## User Experience

Before starting:

- A segmented control selects `Recall meeting` or `Local capture`.
- Recall mode displays a Microsoft Teams URL input.
- Starting is disabled until the URL is valid and Recall credentials are configured.

During startup:

- The title bar shows `Creating bot`, `Waiting in lobby`, or `Connected`.
- Lobby admission remains an explicit human action in Teams.
- A capacity or admission failure displays a direct error with `Retry` and `Use local capture` actions.

During the call:

- The participant picker appears once human participants are available.
- Transcript rows show participant names.
- Existing cards continue to update in place.
- Recall mode never requests microphone or screen-capture permission.

At call end:

- `End Call` requests an idempotent Recall bot stop.
- Clueless drains stored provider events.
- Clueless flushes copilot analysis and persistence.
- The conversation is finalized only after those drains finish or a visible timeout is recorded.

## Failure Behavior

### Input and configuration

- Invalid Teams URL: reject before creating a conversation or bot.
- Missing Recall credentials: open settings and create no session.

### Bot creation

- Ambiguous network timeout: recover by local idempotency key and Recall metadata before retrying.
- `429`: bounded retry using provider delay.
- `507`: mark capture failed and offer local mode without automatic repeated creation.

### Meeting admission

- Lobby state is visible.
- Lobby timeout or host rejection marks capture failed with a sanitized reason.
- Local capture starts only after explicit user selection, never automatically in parallel.

### Webhooks

- Invalid signature: `401`, zero writes.
- Stale timestamp: `401`, zero writes.
- Duplicate webhook ID: `204`, zero duplicate events or transcripts.
- Unsupported event type: `204`, diagnostic counter only.
- Database failure: non-2xx so Recall can retry.

### Event ordering

- Late partial after final: ignored.
- Participant update after transcripts: update identity without duplicating turns.
- Renderer restart: resume after last processed cursor.
- Missing cursor range: refetch from the last durable cursor.

### Shutdown

- Stop requests are idempotent.
- Final provider events are drained before conversation finalization.
- Drain timeout produces a visible system warning and leaves persisted data recoverable.

## Testing Strategy

### Backend

- Recall signature verification: valid, invalid, missing headers, stale timestamp, body mutation, multiple rotation signatures.
- Recall API client: payload shape, regional URL, secret redaction, `429`, `507`, timeout recovery, idempotent stop.
- Meeting service: URL validation, duplicate start, local metadata, lifecycle transitions.
- Webhook controller: verification before parsing, tunnel host restriction, duplicate delivery, immediate durable acknowledgment.
- Event normalizer fixtures: status, named participant, missing name, partial, final, empty words, out-of-order timestamps.
- Persistence: participant upsert, provider transcript replay, role reassignment, provider item uniqueness.
- Migrations: default SQLite and NativePHP SQLite.

### Frontend

- Capture mode selection.
- Recall mode never starts local audio sources.
- Local mode remains unchanged.
- Participant upsert and `This is me` role assignment.
- One-on-one customer assignment.
- Partial replacement by final.
- Late partial ignored after final.
- Cursor replay after composable recreation.
- Final named customer turn reaches `analyzeTurn`.
- Analysis queue does not silently discard provider turns.
- Pain-point and discussion-topic tool calls update Pinia and persist once.
- Named transcript rendering and role alignment.
- Lobby, capacity, signature-ingress, and stop failure states.

### Existing suites

The following must remain green:

- PHP/Pest tests.
- Vitest tests.
- TypeScript typecheck.
- ESLint.
- Vite production build.
- NativePHP/Electron packaging verification relevant to the changed files.

## Live Acceptance Gate

The feature is not considered working until a controlled Teams one-on-one test passes:

1. A bot is created from a pasted Teams URL.
2. The bot enters the lobby and can be admitted.
3. Both humans appear by name.
4. The salesperson can select `This is me`.
5. The other human becomes the customer.
6. Webhook retries and a renderer refresh produce zero duplicate final turns.
7. Fifty scripted turns, including ten overlapping exchanges, produce zero role swaps.
8. Final transcript p95 arrives within four seconds of speech completion.
9. A pain-point or discussion-topic card p95 appears within seven seconds.
10. A thirty-minute call has no missing finalized utterances.
11. Ending the call removes the Recall bot.
12. Transcript and insights appear in conversation history.
13. Local capture still starts and works after Recall mode ends.

Provider availability and host admission cannot be guaranteed by application code. These tests isolate those external conditions and define the evidence required before expanding the integration.

## Rollout

### Phase A1: Contract and backend foundation

Implement configuration, provider contracts, schema, Recall client, signature verification, event normalization, and tests.

### Phase A2: Desktop vertical slice

Implement capture-mode selection, Recall session control, cursor feed, participant role mapping, named transcript UI, and copilot turn delivery.

### Phase A3: Sales intelligence

Implement pain-point and discussion-topic tools, evidence metadata, batching, and persistence.

### Phase A4: Live verification

Run the controlled Teams one-on-one matrix and record the measured results. Fix failures before considering the feature complete.

### Later work

Only after Phase A passes:

- Hosted authenticated ingress.
- Scheduled bots.
- Zoom.
- Google Meet.
- Multi-party stakeholder roles.
- Calendar automation.
- Post-call Recall transcript reconciliation.
- Raw participant audio fallback if transcript quality is insufficient.


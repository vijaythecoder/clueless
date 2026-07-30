# Recall Teams Setup

## Copilot analysis

Recall supplies speaker-attributed transcript turns. By default, Clueless queues
those finalized turns for Laravel-owned OpenAI Responses analysis:

```env
OPENAI_RECALL_ANALYSIS_DRIVER=responses
OPENAI_RECALL_ANALYSIS_MODEL=gpt-5.6-luna
OPENAI_RECALL_ANALYSIS_REASONING_EFFORT=low
```

The `recall-analysis` queue must be running. NativePHP starts its dedicated
worker automatically, while `composer dev` listens to both
`recall-analysis` and `default`.

Local microphone and system-audio capture still use OpenAI Realtime. Set
`OPENAI_RECALL_ANALYSIS_DRIVER=realtime` only as a temporary migration
fallback for Recall meetings.

Clueless can use Recall.ai to join a Microsoft Teams meeting as `Clueless Copilot` and receive speaker-attributed transcript events. Recall capture uses a public webhook, so development requires two separate Laravel processes:

- The normal Clueless application process remains local and serves the desktop UI and private application routes.
- A dedicated ingress process is exposed through an HTTPS tunnel and accepts only the Recall webhook route.

Never point a public tunnel at the NativePHP application port or the normal Laravel application process.

## Prerequisites

- A Recall workspace with Microsoft Teams bot access.
- A Recall API key.
- The workspace webhook verification secret. Recall verification secrets begin with `whsec_`.
- A stable public HTTPS tunnel hostname.
- An OpenAI API key configured separately for the Clueless copilot.

Create the Recall API key and workspace webhook verification secret in the same Recall region. Do not paste either credential into documentation, issue reports, shell history, screenshots, or logs.

## Verification Secret Compatibility

Recall's [current request-verification documentation](https://docs.recall.ai/docs/authenticating-requests-from-recallai) distinguishes workspaces by account creation date:

- Accounts created on or after December 15, 2025 use one workspace verification secret for dashboard webhooks, realtime endpoints, WebSockets, and callbacks.
- Accounts created before December 15, 2025 are legacy workspaces. Dashboard webhooks use the separate Svix secret shown for that dashboard webhook endpoint, and each endpoint can have a different secret. Per-bot realtime webhook endpoints still use the workspace verification secret.

The current Clueless Recall first slice stores one `webhook_secret` and uses it to verify both dashboard lifecycle events and per-bot realtime events at the same route. It therefore assumes an account created on or after December 15, 2025 with the single workspace-secret behavior.

Do not use this first slice live with a legacy workspace. Supplying the legacy dashboard endpoint's Svix secret would break verification for realtime events, while supplying only the workspace secret would break verification for dashboard lifecycle events. Legacy support requires a separate dashboard-webhook secret plus explicit secret selection for dashboard and realtime deliveries before live use.

## Region Configuration

The selected Recall dashboard region, API key, workspace verification secret, and API base URL must all belong to the same region. Clueless defaults to `us-west-2`:

```dotenv
RECALL_REGION=us-west-2
RECALL_BASE_URL=https://us-west-2.recall.ai
```

If the Recall workspace uses another region, set both values to that region and its matching Recall API hostname. `RECALL_BASE_URL` controls API requests; `RECALL_REGION` is also shown in the Recall settings status. A region label that does not match the base URL is a configuration error.

## Migrate Both Databases

This repository maintains two SQLite databases. Run the Recall migrations against both before starting either process:

```bash
php artisan migrate --force
php artisan migrate --database=nativephp --force
php artisan migrate:status
php artisan migrate:status --database=nativephp
```

The Recall schema is introduced by:

- `database/migrations/2026_07_27_000001_create_meeting_capture_tables.php`
- `database/migrations/2026_07_27_000002_add_provider_metadata_to_conversation_transcripts.php`
- `database/migrations/2026_07_27_000003_add_provider_utterance_key_to_provider_events.php`
- `database/migrations/2026_07_27_000004_add_completed_lease_token_hash_to_meeting_analysis_deliveries.php`
- `database/migrations/2026_07_27_000005_add_evidence_snapshot_to_meeting_analysis_deliveries.php`

Both processes must use the same application key and the same active SQLite data as the running application. The ingress process needs access to the encrypted Recall verification secret and the local capture rows used to associate incoming events.

## Configure Credentials

1. Start Clueless locally without exposing it to the internet.
2. Open **Settings > Recall**.
3. Enter the Recall API key and workspace webhook verification secret.
4. Save the settings.
5. Confirm that the page shows **Recall is configured**, the intended region, and the intended webhook URL.

The credentials are stored through Laravel's encrypted `SecureSetting` cast. Stored values are not returned to the renderer; the settings page receives only configured/not-configured flags.

The configured indicator confirms only that both values are present. It does not verify them with Recall:

- The API key is verified only when Recall accepts a bot API request.
- The workspace verification secret is verified only when Clueless accepts a genuine signed Recall webhook.

Environment fallbacks named `RECALL_API_KEY` and `RECALL_WEBHOOK_SECRET` exist, but the settings page is preferred for local desktop use. Never commit credential values.

## Configure The Webhook

Choose one stable public hostname and set these values for the normal application process:

```dotenv
RECALL_WEBHOOK_URL=https://recall.example.com/api/recall/webhooks
RECALL_TUNNEL_HOST=recall.example.com
RECALL_PARTIAL_TRANSCRIPTS=false
```

Replace `recall.example.com` with the actual tunnel hostname. `RECALL_TUNNEL_HOST` must contain only the hostname, without a scheme, port, or path. `RECALL_WEBHOOK_URL` must use HTTPS and the exact `/api/recall/webhooks` path.

Final transcript events are enabled by default. Keep partial transcripts disabled for the first live gate. When `RECALL_PARTIAL_TRANSCRIPTS=true`, Clueless asks Recall for `transcript.partial_data`; partials are display-only and are not sent to copilot analysis. Enable them only after finalized-turn behavior is stable.

Restart the normal application after changing environment configuration.

## Run The Two Processes

### 1. Normal application

Run the application normally on its local-only port:

```bash
composer native:dev
```

Do not expose this process through the tunnel.

### 2. Dedicated Recall ingress

Start a second Laravel process from the same checkout and environment. In desktop development, explicitly select the `nativephp` connection so the ingress process reads the same capture rows and encrypted settings as the NativePHP application. Clear stale development config first, and keep debug responses disabled on the public process:

```bash
php artisan config:clear
APP_DEBUG=false DB_CONNECTION=nativephp RECALL_INGRESS_ONLY=true php artisan serve --host=127.0.0.1 --port=8101
```

`RECALL_INGRESS_ONLY=true` is read directly from the ingress process environment, so it remains authoritative even if Laravel configuration was previously cached. The ingress middleware denies every request except the exact method and path:

```text
POST /api/recall/webhooks
```

This restriction applies regardless of the request's `Host` header. Hostname filtering on the normal application is an additional guard, not a substitute for the dedicated ingress process.

Do not omit `DB_CONNECTION=nativephp` during desktop development. A plain Artisan process otherwise defaults to `database/database.sqlite`, while NativePHP rewrites its active connection to `database/nativephp.sqlite`; valid webhooks would then fail to find the bot's capture session.

### 3. HTTPS tunnel

Point the stable HTTPS tunnel to:

```text
http://127.0.0.1:8101
```

Never point it to the NativePHP or normal Laravel application port. Restart the ingress process after environment changes, then restart or reconnect the tunnel if its configuration changed.

## Configure Recall Event Delivery

Clueless uses the same signed Laravel endpoint for two distinct Recall delivery configurations.

### Per-bot realtime endpoint

Clueless includes `RECALL_WEBHOOK_URL` in each bot creation request under `recording_config.realtime_endpoints`. The per-bot endpoint subscribes to:

- `participant_events.join`
- `participant_events.update`
- `participant_events.leave`
- `transcript.data`
- `transcript.partial_data` only when partial transcripts are enabled

Do not manually add bot lifecycle events to this per-bot realtime subscription.

### Dashboard lifecycle webhook

In the Recall dashboard **Webhooks** tab, add the exact `RECALL_WEBHOOK_URL` once for bot lifecycle status events. Lifecycle events are configured at the workspace/dashboard level; they are not valid per-bot realtime endpoint subscriptions.

The dashboard webhook and per-bot realtime endpoint may target the same URL, but they serve different event families. Omitting either configuration produces incomplete behavior: realtime transcription can work without lifecycle updates, or lifecycle updates can arrive without transcript events.

This shared URL and shared-secret setup is supported by the current Clueless code only for Recall accounts created on or after December 15, 2025. Stop here for a legacy workspace; do not create a live bot until separate-secret verification support has been implemented.

## Verify The Ingress Boundary

Run these checks only after the tunnel points to port `8101`:

```bash
curl -i https://recall.example.com/
curl -i https://recall.example.com/realtime-agent
curl -i https://recall.example.com/api/recall/webhooks
curl -i -X POST https://recall.example.com/api/recall/webhooks \
  -H 'Content-Type: application/json' \
  --data '{}'
```

Replace the example hostname before running the commands. The expected boundary behavior is:

- Any non-webhook path returns `404`.
- `GET /api/recall/webhooks` returns `404`.
- An unsigned `POST /api/recall/webhooks` returns `401`.
- A body larger than `RECALL_WEBHOOK_MAX_BYTES` returns `413`; the default limit is 1 MiB.
- A genuine, valid signed event for a known bot can proceed to normalization and persistence.

The webhook verifies the untouched request body before JSON decoding. It requires Recall's `webhook-id`, `webhook-timestamp`, and `webhook-signature` headers, accepts current `v1` signatures, and rejects timestamps outside `RECALL_WEBHOOK_TOLERANCE_SECONDS` (300 seconds by default).

These checks validate routing and rejection behavior only. They are not a substitute for the separate live Teams acceptance gate.

## Safe Troubleshooting

### Recall settings say configured, but bot creation fails

- Confirm the API key and `RECALL_BASE_URL` belong to the same Recall region.
- Confirm `RECALL_REGION` matches that region.
- Restart the normal application after configuration changes.
- Treat the settings indicator as presence-only; it does not make a Recall API request.

### Webhook requests return `401`

- Confirm the workspace verification secret came from the same Recall workspace and region as the bot API key.
- Confirm the account was created on or after December 15, 2025. A legacy dashboard webhook uses its endpoint-specific Svix secret, which this first slice does not yet support alongside the realtime workspace secret.
- Confirm the tunnel or proxy does not rewrite the request body.
- Confirm the machine clock is synchronized; stale timestamps are rejected.
- Do not print the signing secret or raw authorization headers while diagnosing.

### Webhook paths return something other than the expected `404`

- Stop the tunnel immediately.
- Confirm it targets port `8101`, not the app port.
- Confirm the ingress process environment contains `RECALL_INGRESS_ONLY=true`.
- Restart the ingress process after clearing stale config.

### Lifecycle updates arrive without transcripts

- Confirm the bot creation process used the same `RECALL_WEBHOOK_URL`.
- Confirm the per-bot realtime endpoint contains `transcript.data`.
- Remember that dashboard lifecycle webhooks do not configure per-bot transcript delivery.

### Transcripts arrive without lifecycle updates

- Add the exact `RECALL_WEBHOOK_URL` once in the Recall dashboard **Webhooks** tab.
- Confirm the dashboard webhook belongs to the same workspace and region.

### Partial transcripts do not appear

This is expected while `RECALL_PARTIAL_TRANSCRIPTS=false`. Final `transcript.data` events remain enabled. Do not enable partials to work around missing final events.

### Capacity or rate-limit errors

- A Recall `507` response means bot capacity is unavailable. Use local capture or wait; do not create bots in a tight retry loop.
- Recall `429` responses are retried by the API client with bounded handling of `Retry-After`. Persistent rate limiting should be investigated in the Recall workspace.

Keep `APP_DEBUG=false` on the public ingress process. Share only status codes, Clueless error codes, event types, and redacted timestamps when troubleshooting. Never share API keys, verification secrets, signed headers, meeting URLs, raw webhook bodies, or participant email addresses.

# Recall Teams Realtime Copilot Verification

Verified from the local checkout on 2026-07-27 at 11:58 CDT.

## Automated Checks

| Command | Exit | Result |
| --- | ---: | --- |
| `php artisan test` | 0 | 336 tests passed, 1532 assertions |
| `npm run test:frontend` | 0 | 17 files passed, 135 tests passed |
| `npm run test:native` | 0 | 18 Electron extension, main, preload, and patch tests passed |
| `npm run typecheck` | 0 | Vue TypeScript check passed |
| `npm run lint:check` | 0 | ESLint passed |
| `npm run format:check` | 0 | All resource files match Prettier |
| `vendor/bin/pint --test` | 0 | 162 PHP files passed |
| `npm run build` | 0 | Vite production build completed; 3240 modules transformed |
| `node scripts/apply-nativephp-electron-patch.mjs` | 0 | Refreshed four local NativePHP vendor files |
| `node scripts/apply-nativephp-electron-patch.mjs --check` | 0 | NativePHP hardening patch is applied |
| `node scripts/verify-nativephp-packaging.mjs` | 0 | Audio helper contains arm64 and x86_64 slices |
| `composer native:dev` | running | Patched NativePHP desktop runtime launched without a startup error |
| `curl -I http://127.0.0.1:8100/realtime-agent` | 0 | Local application route returned `200 OK` |
| `php artisan migrate --force` | 0 | Nothing pending on `database.sqlite` |
| `php artisan migrate --database=nativephp --force` | 0 | Nothing pending on `nativephp.sqlite` |
| `php artisan migrate:status` | 0 | Every migration through `2026_07_27_000005` is `Ran` |
| `php artisan migrate:status --database=nativephp` | 0 | Every migration through `2026_07_27_000005` is `Ran` |
| `git diff --check` | 0 | No whitespace errors |

## Review

The final independent review approved the implementation after confirming:

- application quit, native window close, and Inertia navigation wait for deterministic call shutdown;
- lifecycle generations and one-time close requests reject stale, replayed, and cross-window transitions;
- Local pain points and discussion topics survive persistence, history rendering, and metrics;
- stale OpenAI Realtime create/cancel errors cannot consume a newer analysis attempt's retries;
- native main/preload/extension tests are invoked by `npm run test:native` in CI.

## Live Checks

Not run.

The live gate requires configured Recall credentials, a stable public HTTPS ingress process, available bot capacity, and a real Microsoft Teams 1:1 meeting. None of those external inputs was available during this verification run.

The following plan gates therefore remain unverified:

- public tunnel returns `404` outside the signed webhook and `401` for an unsigned webhook;
- bot creation, lobby admission, participant naming, and explicit salesperson assignment;
- 50-turn overlap/role accuracy and 30-minute completeness;
- measured transcript p95 at most four seconds and sales-card p95 at most seven seconds;
- refresh replay, end-call bot removal, final drain, and real history inspection;
- packaged-app close/navigation behavior during real Local and Recall calls.

## Residual Risks

- Recall workspace secret behavior differs for legacy workspaces created before 2025-12-15; the first live slice requires the current single-workspace-secret behavior documented in `docs/recall-teams-setup.md`.
- Provider latency, Teams lobby policy, Recall capacity, and participant identity quality cannot be established by automated tests.
- System shutdown or a forcibly killed renderer can bypass Electron's cooperative quit lifecycle.

This implementation is automated-test complete but is not declared production-ready until every live gate above passes.

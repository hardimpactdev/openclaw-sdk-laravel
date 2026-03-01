# Changelog

## [0.2.1] - 2026-03-01

### Fixed

- **Migration stub**: Added missing `last_seen_at` column to wake fields migration
- **Null age coercion**: `CheckAgentStatusCommand` now marks agents as sleeping when gateway reports null age instead of coercing to 0
- **Route model binding**: `GatewayTokenAuth` middleware now works with Laravel route model binding and uses the route param name for column lookup instead of hardcoded `name`
- **ws:// scheme**: `wssToHttps()` now handles both `wss://` → `https://` and `ws://` → `http://` conversions
- **Hardcoded CA path**: Config default changed from hardcoded home directory path to `null`; CA bundle is now conditionally applied in both WebSocket and Wake clients (falls back to system CA)
- **canWake mutation**: `canWake()` no longer mutates `wake_count_minute` as a side effect — uses a local variable for the RPM check
- **RPM gap in secondsUntilCanWake**: `secondsUntilCanWake()` now accounts for RPM rate limits, not just backoff and interval
- **Division by zero**: `recordWakeFailure()` guards against zero base backoff config with `max(1, ...)`
- **Retry-after clamping**: `recordRateLimitResponse()` no longer clamps short server retry-after values up to the base backoff — respects the server's value (still capped at max)
- **Non-atomic counters**: `recordWakeSuccess()`, `recordWakeFailure()`, and `recordRateLimitResponse()` use `DB::raw()` for atomic counter increments
- **Gateway grouping**: `CheckAgentStatusCommand` groups gateways by URL+token (not just URL), preventing token mix-ups across gateways sharing a URL
- **Signed time diffs**: All Carbon `diffInMinutes`/`diffInSeconds` calls use signed mode to handle edge cases with future timestamps
- **Empty gateway URLs**: `CheckAgentStatusCommand` filters out empty string gateway URLs in addition to null

### Changed

- Event docblocks (`AgentWoken`, `AgentWakeFailed`) clarify they are data structures for consumer dispatch, not auto-dispatched by the SDK

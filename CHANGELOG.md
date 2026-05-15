# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] — 2026-05-13

### Added

- Initial release
- `AlertManager` service with `notify()` and `resolve()` methods
- `PushoverChannel` — sends push notifications via Pushover REST API
- `AlertChannelInterface` for future channel implementations
- `Alert` value object with `source`, `eventKey`, `message`, `severity`, `context`
- `AlertSeverity` enum: INFO, WARNING, ERROR, CRITICAL
- `AlertStatus` enum: NEW, NOTIFIED, RESOLVED
- `AlertEventRepository` — DB-backed event log (`tx_otalerts_events`) with upsert and state transitions
- Rate limiting: immediate notification on first occurrence, configurable reminder interval for recurring errors
- State machine: RESOLVED errors reset to NEW on next `notify()` call, triggering fresh notification
- Graceful degradation: channels are skipped when not configured (missing ENV vars)
- Configurable `reminderInterval` via TYPO3 Extension Configuration (default: 3600 seconds)
- Pushover credentials via environment variables `PUSHOVER_APP_TOKEN` and `PUSHOVER_USER_KEY`
- CLI command `ot_alerts:test` to verify channel configuration and trigger a test notification
- `--severity` option (info/warning/error/critical) and `--resolve` flag to reset rate limit after test
- Full Pushover priority mapping: INFO→Low(-1), WARNING→Normal(0), ERROR→High(1), CRITICAL→Emergency(2)
- Emergency priority support: configurable `pushoverEmergencyRetry` and `pushoverEmergencyExpire` via Extension Configuration
- HTML-formatted Pushover messages: event key bold, occurrence count italic
- Optional tappable link button in Pushover via `context['url']` on the `Alert` value object
- Optional dependency pattern via nullable constructor injection (`?AlertManager $alertManager = null`) — no hard dependency required
- `phpstan.neon.dist` — PHPStan level 9 configuration
- `LICENSE` (GPL-2.0-or-later) and `.editorconfig`

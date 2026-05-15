# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- `ext_tables.sql`: removed redundant prefix lengths from `UNIQUE KEY source_event` — `source(100)` and `event_key(200)` match the column widths exactly, causing Doctrine DBAL to perpetually suggest `DROP INDEX` + `CREATE UNIQUE INDEX` in the TYPO3 database analyzer

## [0.2.0] — 2026-05-15

### Added

- TYPO3 v14 support (`^13.4 || ^14.0`) — version constraints updated in `composer.json` and `ext_emconf.php`
- `ot_alerts:test --verbose` / `-v` — shows per-channel dispatch result including Pushover HTTP status code and raw API response body

### Changed

- Pushover notification title now includes the server hostname: `[ERROR] my_extension @ www.example.com` — makes it immediately clear which environment (DDEV, staging, live) triggered the alert when multiple systems share the same Pushover credentials
- `AlertChannelInterface::send()` return type changed from `void` to `array{sent: bool, channel: string, httpStatus?: int, body?: string, error?: string}` — enables callers to inspect the channel result
- `AlertManager::notify()` return type changed from `void` to `array{sent: bool, reason: string, channels: list<...>}` — reason values: `new`, `reminder`, `rate_limited`, `no_channels`, `error`
- `PushoverChannel` and `AlertManager` now use `Psr\Log\LoggerInterface` constructor injection instead of `GeneralUtility::makeInstance(LogManager::class)` (TYPO3 v13/v14 standard DI pattern)

### Fixed

- `composer.json description` now follows the `"Title - Description"` convention required by TYPO3 v14 Extension Manager to avoid the "Extension Title missing" warning
- `ot_alerts:test` now always shows the raw Pushover API response (HTTP status + JSON body) directly in the command output — `[OK]` is no longer printed without a confirmed API response; the `-v`/`-vvv` flags are no longer needed for diagnostics
- `ot_alerts:test --resolve` was silently ignored when the rate limit was active — the command returned early before reaching the resolve call. Fixed by pre-resolving before `notify()` (resets status to RESOLVED so `upsertEvent()` immediately transitions back to NEW and `shouldNotify()` allows the send)
- `ot_alerts:test` previously printed `[OK] dispatched` even when the alert was silently skipped due to an active rate limit — the command now shows an explicit `[WARNING]`
- Removed post-resolve after successful dispatch: `--resolve` is now a single one-time bypass only; the event status is always `notified` after a successful send, making the flow predictable regardless of flags
- The `[NOTE]` after a successful dispatch now consistently states the rate-limit consequence instead of confusingly mentioning "resolved"

## [0.1.1] — 2026-05-15

### Added

- `phpstan.neon.dist` — PHPStan level 9 configuration
- `LICENSE` (GPL-2.0-or-later) and `.editorconfig`
- Optional dependency pattern via nullable constructor injection (`?AlertManager $alertManager = null`) documented in README

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

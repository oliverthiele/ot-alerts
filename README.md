# ot_alerts — Alert Management for TYPO3 Extensions

Centralised alert proxy for TYPO3 extensions — sends Pushover push notifications
with built-in
rate limiting and deduplication. Other extensions simply call
`AlertManager::notify()` and
`AlertManager::resolve()`; ot_alerts handles throttling, state tracking, and
channel dispatch.

[![TYPO3](https://img.shields.io/badge/TYPO3-13.4_%7C_14-orange.svg)](https://typo3.org/)
[![Packagist Version](https://img.shields.io/packagist/v/oliverthiele/ot-alerts.svg)](https://packagist.org/packages/oliverthiele/ot-alerts)
[![PHP](https://img.shields.io/packagist/dependency-v/oliverthiele/ot-alerts/php.svg)](https://php.net/)
[![License](https://img.shields.io/packagist/l/oliverthiele/ot-alerts.svg)](LICENSE)
[![Changelog](https://img.shields.io/badge/Changelog-CHANGELOG.md-blue.svg)](CHANGELOG.md)

## Features

- Pushover push notifications via REST API with HTML formatting
- Event key and occurrence count visible in every notification
- Optional tappable link button in Pushover (via `context['url']`)
- Rate limiting: first occurrence triggers immediately, then once per
  configurable reminder interval
- State machine: NEW → NOTIFIED → RESOLVED — resolved errors trigger fresh
  notifications when they reappear
- DB-backed event log (`tx_otalerts_events`) for audit trail and future backend
  module
- Optional integration: inject `?AlertManager` via constructor — ot_alerts is
  not a hard dependency, the service is simply `null` when not installed
- Configurable reminder interval via TYPO3 Extension Configuration, with optional per-alert override

## Requirements

| Requirement       | Version          |
|-------------------|------------------|
| TYPO3             | ^13.4 \|\| ^14.0 |
| PHP               | ^8.4             |
| guzzlehttp/guzzle | ^7.0             |

## Installation

```bash
composer require oliverthiele/ot-alerts
```

After installation, run database schema update:

```bash
vendor/bin/typo3 database:updateschema
```

## Configuration

### Environment Variables

Add the following to your `.env` file:

```dotenv
PUSHOVER_APP_TOKEN=your_app_token_here
PUSHOVER_USER_KEY=your_user_key_here
```

Both values are available in your [Pushover dashboard](https://pushover.net/).

### Extension Configuration

Configure in the TYPO3 backend under **Admin Tools → Settings → Extension
Configuration → ot_alerts**:

| Key                       | Type | Default | Description                                          |
|---------------------------|------|---------|------------------------------------------------------|
| `reminderInterval`        | int  | `3600`  | Seconds between reminder notifications (1 hour)      |
| `pushoverEmergencyRetry`  | int  | `60`    | Seconds between retries for CRITICAL alerts (min 30) |
| `pushoverEmergencyExpire` | int  | `3600`  | Seconds until Pushover stops retrying (max 10800)    |

## Usage

### Optional dependency via constructor injection

The recommended integration pattern uses TYPO3's Symfony DI container. Declare
`?AlertManager`
as a nullable constructor parameter — when ot_alerts is not installed, the
container injects
`null` and all calls are silently skipped via the null-safe operator:

```php
use OliverThiele\OtAlerts\Alert\Alert;
use OliverThiele\OtAlerts\Alert\AlertSeverity;
use OliverThiele\OtAlerts\Service\AlertManager;

class MyService
{
    public function __construct(
        private readonly ?AlertManager $alertManager = null,
    ) {}

    public function doSomething(): void
    {
        // ... your logic ...

        // notify — only fires when ot_alerts is installed
        $this->alertManager?->notify(new Alert(
            source: 'my_extension',
            eventKey: 'api.connection.failed',
            message: 'Could not connect to external API',
            severity: AlertSeverity::ERROR,
        ));
    }
}
```

No `class_exists()` guard or `GeneralUtility::makeInstance()` needed — the
container resolves
the optional service automatically.

### Sending an alert

```php
$this->alertManager?->notify(new Alert(
    source: 'my_extension',
    eventKey: 'api.connection.failed',
    message: 'Could not connect to external API',
    severity: AlertSeverity::ERROR,
));
```

### Sending an alert with a URL

Pass `context['url']` to add a tappable link button to the Pushover
notification.
This is useful to open the affected page directly from the push:

```php
$this->alertManager?->notify(new Alert(
    source: 'my_extension',
    eventKey: 'api.connection.failed',
    message: 'Could not connect to external API',
    severity: AlertSeverity::ERROR,
    context: ['url' => 'https://example.com/affected-page/'],
));
```

### Sending an alert with a per-alert reminder interval

Pass `reminderInterval` to override the global extension configuration for this
specific alert. Useful when certain events need a shorter or longer throttle than
the global default:

```php
$this->alertManager?->notify(new Alert(
    source: 'my_extension',
    eventKey: 'quota.warning',
    message: 'API quota at 90 %',
    severity: AlertSeverity::WARNING,
    reminderInterval: 300, // remind every 5 minutes instead of the global default
));
```

### Resolving an alert

Call `resolve()` once the error condition is no longer present. This resets the
state so the
next occurrence will trigger a fresh notification immediately.

```php
$this->alertManager?->resolve('my_extension', 'api.connection.failed');
```

### Notification format

Pushover messages use HTML formatting for readability:

```
Title:    [ERROR] my_extension

Message:  api.connection.failed       ← bold event key

          Could not connect to
          external API

          occurrence #3               ← italic, only shown from 2nd occurrence onwards

[Open page →]                         ← tappable link button, only shown when context['url'] is set
```

### Severity levels and Pushover priorities

| Severity   | Pushover priority | Behaviour                                                          |
|------------|-------------------|--------------------------------------------------------------------|
| `INFO`     | Low (-1)          | Quiet notification, no sound                                       |
| `WARNING`  | Normal (0)        | Default sound and vibration                                        |
| `ERROR`    | High (1)          | Bypasses quiet hours                                               |
| `CRITICAL` | Emergency (2)     | Repeated every `pushoverEmergencyRetry` seconds until acknowledged |

Emergency notifications (CRITICAL) require acknowledgement in the Pushover app.

### Notification behaviour

```
First error  →  Push sent immediately  →  status: NOTIFIED
Still broken →  Push after reminderInterval  →  status: NOTIFIED
Error fixed  →  resolve() called  →  status: RESOLVED
New error    →  Push sent immediately  →  status: NOTIFIED
```

## CLI

### Test notification

Verify that Pushover credentials are configured and a push is delivered:

```bash
vendor/bin/typo3 ot_alerts:test
```

Send with a specific severity:

```bash
vendor/bin/typo3 ot_alerts:test --severity=error
```

Reset the rate limit after the test so the next real error triggers immediately:

```bash
vendor/bin/typo3 ot_alerts:test --resolve
```

Show per-channel dispatch result including HTTP status:

```bash
vendor/bin/typo3 ot_alerts:test -v
```

Show the raw Pushover API response body (full debug output):

```bash
vendor/bin/typo3 ot_alerts:test -vvv
```

If the alert was previously sent and the reminder interval has not yet elapsed,
the command shows `[WARNING] Rate limit active`. Use `--resolve` to bypass:
the command pre-resolves the event before sending (so the rate limit is skipped)
and post-resolves after (so the next test also sends immediately).

The command shows which ENV variables are present, dispatches the alert, and
prints the result.

## License

GPL-2.0-or-later — © 2025 Oliver Thiele

# OT Alerts — Alert management for TYPO3 extensions

Other extensions call `AlertManager::notify()` and `AlertManager::resolve()`;
OT Alerts sends Pushover push notifications and takes care of rate limiting,
deduplication and the state of every event.

[![TYPO3](https://img.shields.io/badge/TYPO3-14.3-orange.svg)](https://typo3.org/)
[![Packagist Version](https://img.shields.io/packagist/v/oliverthiele/ot-alerts.svg)](https://packagist.org/packages/oliverthiele/ot-alerts)
[![PHP](https://img.shields.io/packagist/dependency-v/oliverthiele/ot-alerts/php.svg)](https://php.net/)
[![License](https://img.shields.io/packagist/l/oliverthiele/ot-alerts.svg)](LICENSE)
[![Changelog](https://img.shields.io/badge/Changelog-CHANGELOG.md-blue.svg)](CHANGELOG.md)

---

## Features

- **Pushover notifications** — HTML formatted, with the event key, the
  message and an optional link button; messages longer than the Pushover limit
  are shortened instead of rejected
- **Rate limiting** — the first occurrence is sent at once, then one reminder
  per configurable interval, also per alert
- **Deduplication across processes** — when many requests report the same
  event at the same moment, exactly one notification goes out
- **Event states** — new → notified → resolved; a resolved event that returns
  is sent again at once and counted from one
- **Transactional notifications** — `throttle: false` delivers every single
  time, for events such as a received order
- **Event log** — one row per event in `tx_otalerts_events`, on every database
  TYPO3 supports
- **Optional dependency** — inject `?AlertManager`; without OT Alerts installed
  the container passes `null`
- **Never in the way** — `notify()` does not throw, and the request to
  Pushover gives up after five seconds

---

## Requirements

| Requirement | Version |
|-------------|---------|
| TYPO3       | ^14.3   |
| PHP         | >=8.3   |

A [Pushover](https://pushover.net/) account with an application token.

---

## Installation

```bash
composer require oliverthiele/ot-alerts
```

Then run the TYPO3 setup, which creates the event table:

```bash
vendor/bin/typo3 extension:setup -e ot_alerts
# or via DDEV:
ddev typo3 extension:setup -e ot_alerts
```

---

## Configuration

### Pushover credentials

Set two environment variables, both shown in your
[Pushover dashboard](https://pushover.net/):

```dotenv
PUSHOVER_APP_TOKEN=your_app_token_here
PUSHOVER_USER_KEY=your_user_key_here
```

They can come from a `.env` file or from the environment of the process —
`export`, a cron entry, the webserver configuration. Each variable is read from
`$_ENV` first, then with `getenv()`, so they are found whether or not
`variables_order` contains `E`.

Without both values the channel counts as not configured, and `notify()`
reports `no_channels`.

### Extension configuration

**Admin Tools → Settings → Extension Configuration → ot_alerts**:

| Key                       | Type | Default | Description                                          |
|---------------------------|------|---------|------------------------------------------------------|
| `reminderInterval`        | int  | `3600`  | Seconds between reminder notifications (1 hour)      |
| `pushoverEmergencyRetry`  | int  | `60`    | Seconds between retries for CRITICAL alerts (min 30) |
| `pushoverEmergencyExpire` | int  | `3600`  | Seconds until Pushover stops retrying (max 10800)    |

---

## Usage

### Optional dependency via constructor injection

Declare `?AlertManager` as a nullable constructor parameter. When OT Alerts is
not installed, the container passes `null` and the null-safe operator skips
every call — no `class_exists()` guard needed:

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

        $this->alertManager?->notify(new Alert(
            source: 'my_extension',
            eventKey: 'api.connection.failed',
            message: 'Could not connect to external API',
            severity: AlertSeverity::ERROR,
        ));
    }
}
```

### Link button

`context['url']` adds a tappable button that opens the affected page. Only
`http` and `https` URLs of up to 512 characters are passed on:

```php
$this->alertManager?->notify(new Alert(
    source: 'my_extension',
    eventKey: 'api.connection.failed',
    message: 'Could not connect to external API',
    severity: AlertSeverity::ERROR,
    context: ['url' => 'https://example.com/affected-page/'],
));
```

### Reminder interval per alert

`reminderInterval` overrides the configured interval for one alert:

```php
$this->alertManager?->notify(new Alert(
    source: 'my_extension',
    eventKey: 'quota.warning',
    message: 'API quota at 90 %',
    severity: AlertSeverity::WARNING,
    reminderInterval: 300, // every 5 minutes instead of the configured interval
));
```

### Transactional notifications

Not everything worth a push is an error. A submitted form, a completed import,
an incoming order — these carry their own occasion and have to be delivered
every single time. Rate limiting would swallow the second one within the
reminder interval.

Pass `throttle: false` for those. The rate limit and the event state are then
skipped, and the occurrence counter is left out of the message, because it
describes a condition that keeps repeating:

```php
$this->alertManager?->notify(new Alert(
    source: 'my_extension',
    eventKey: 'order.received',
    message: 'New order #4711 — Jane Doe, 249.00 EUR',
    severity: AlertSeverity::NOTICE,
    throttle: false,
));
```

`notify()` reports these dispatches as `reason: notification`. The event row is
still written, so the log keeps `last_message`, `last_occurrence` and the
count.

`NOTICE` is the matching severity: audible like `WARNING`, but the title reads
`[NOTICE] my_extension` and does not claim that something is wrong.

### Resolving an event

Call `resolve()` once the condition is gone. The next occurrence is sent at
once and counted from one:

```php
$this->alertManager?->resolve('my_extension', 'api.connection.failed');
```

### What `notify()` returns

```php
['sent' => bool, 'reason' => string, 'channels' => list<array>]
```

| `reason`       | Meaning                                                        |
|----------------|----------------------------------------------------------------|
| `new`          | First occurrence, or the first after `resolve()`               |
| `reminder`     | Still occurring, and the reminder interval is over             |
| `notification` | Sent with `throttle: false`                                    |
| `rate_limited` | Held back: already notified within the interval                |
| `no_channels`  | No channel is configured                                       |
| `error`        | Something failed; the details are in the TYPO3 log             |

When no channel delivers a notification, the event is not marked as notified:
the next occurrence tries again instead of waiting for the interval.

### Notification format

```
Title:    [ERROR] my_extension @ hostname

Message:  api.connection.failed       ← bold event key

          Could not connect to
          external API

          occurrence #3               ← from the 2nd occurrence on,
                                        omitted with throttle: false

[Open page]                           ← only with context['url']
```

Pushover accepts 1024 characters per message and 250 per title. Longer texts
are shortened and end with `…`.

### Severity levels and Pushover priorities

| Severity   | Pushover priority | Behaviour                                                          |
|------------|-------------------|--------------------------------------------------------------------|
| `INFO`     | Low (-1)          | Quiet notification, no sound                                       |
| `NOTICE`   | Normal (0)        | Default sound and vibration — nothing is wrong, just worth knowing |
| `WARNING`  | Normal (0)        | Default sound and vibration                                        |
| `ERROR`    | High (1)          | Bypasses quiet hours                                               |
| `CRITICAL` | Emergency (2)     | Repeated every `pushoverEmergencyRetry` seconds until acknowledged |

### Notification behaviour

```
First error  →  push sent at once         →  status: notified
Still broken →  push after the interval   →  status: notified
Error fixed  →  resolve() called          →  status: resolved
New error    →  push sent at once         →  status: notified
```

With `throttle: false` none of this applies — every `notify()` pushes.

---

## CLI

### `ot_alerts:test`

Checks the Pushover credentials, sends a test alert and prints the answer of
the Pushover API:

```bash
vendor/bin/typo3 ot_alerts:test
vendor/bin/typo3 ot_alerts:test --severity=error
```

| Option          | Description                                                                 |
|-----------------|-----------------------------------------------------------------------------|
| `--severity`    | `info`, `notice`, `warning`, `error` or `critical` (default: `info`)        |
| `--resolve`     | Resolve the test event before sending, so the rate limit does not hold it back |
| `--no-throttle` | Send as a transactional notification, the way `throttle: false` does        |

The test event is rate limited like any other: a second run within the
reminder interval reports `Rate limit active`. Use `--resolve` or
`--no-throttle` to send again.

---

## License

GPL-2.0-or-later — see [LICENSE](LICENSE)

---

## Author

Oliver Thiele — [oliver-thiele.de](https://www.oliver-thiele.de)

<?php

declare(strict_types=1);

namespace OliverThiele\OtAlerts\Channel;

/**
 * The Pushover application token and user key, read from the environment
 * variables PUSHOVER_APP_TOKEN and PUSHOVER_USER_KEY.
 *
 * Each variable is read from $_ENV first, then with getenv(). Both are needed:
 * vlucas/phpdotenv without the putenv adapter only populates $_ENV, while the
 * real environment of the process — export, a cron entry, the webserver
 * configuration — only reaches $_ENV when variables_order contains "E", which
 * php.ini-production leaves out.
 */
final readonly class PushoverCredentials
{
    public const string APP_TOKEN_VARIABLE = 'PUSHOVER_APP_TOKEN';
    public const string USER_KEY_VARIABLE = 'PUSHOVER_USER_KEY';

    public function __construct(
        public string $appToken,
        public string $userKey,
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(self::read(self::APP_TOKEN_VARIABLE), self::read(self::USER_KEY_VARIABLE));
    }

    public function isComplete(): bool
    {
        return $this->appToken !== '' && $this->userKey !== '';
    }

    private static function read(string $name): string
    {
        $value = $_ENV[$name] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }
        $value = getenv($name);

        return is_string($value) ? $value : '';
    }
}

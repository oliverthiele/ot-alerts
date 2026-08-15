<?php

declare(strict_types=1);

namespace OliverThiele\OtAlerts\Alert;

final readonly class Alert
{
    /**
     * @param array<string, mixed> $context
     * @param int|null $reminderInterval Overrides the globally configured reminder interval, in seconds
     * @param bool $throttle Set to false for transactional notifications that have to be delivered
     *                       every single time. Rate limiting is then skipped, and so is the
     *                       occurrence counter in the message, which only makes sense for a
     *                       condition that repeats
     */
    public function __construct(
        public string $source,
        public string $eventKey,
        public string $message,
        public AlertSeverity $severity,
        public array $context = [],
        public ?int $reminderInterval = null,
        public bool $throttle = true,
    ) {
    }
}

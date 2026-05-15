<?php

declare(strict_types=1);

namespace OliverThiele\OtAlerts\Alert;

final readonly class Alert
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $source,
        public string $eventKey,
        public string $message,
        public AlertSeverity $severity,
        public array $context = [],
    ) {
    }
}

<?php

declare(strict_types=1);

namespace OliverThiele\OtAlerts\Channel;

use OliverThiele\OtAlerts\Alert\Alert;

interface AlertChannelInterface
{
    public function getIdentifier(): string;

    public function isConfigured(): bool;

    /** @param array<string, mixed> $event */
    public function send(Alert $alert, array $event): void;
}

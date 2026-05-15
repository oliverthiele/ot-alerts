<?php

declare(strict_types=1);

namespace OliverThiele\OtAlerts\Channel;

use OliverThiele\OtAlerts\Alert\Alert;

interface AlertChannelInterface
{
    public function getIdentifier(): string;

    public function isConfigured(): bool;

    /**
     * @param array<string, mixed> $event
     * @return array{sent: bool, channel: string, httpStatus?: int, body?: string, error?: string}
     */
    public function send(Alert $alert, array $event): array;
}

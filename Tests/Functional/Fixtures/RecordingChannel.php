<?php

declare(strict_types=1);

namespace OliverThiele\OtAlerts\Tests\Functional\Fixtures;

use OliverThiele\OtAlerts\Alert\Alert;
use OliverThiele\OtAlerts\Channel\AlertChannelInterface;

/**
 * Stands in for Pushover: records every dispatch and answers as told.
 */
final class RecordingChannel implements AlertChannelInterface
{
    /** @var list<Alert> */
    public array $sentAlerts = [];

    public bool $delivers = true;

    public function getIdentifier(): string
    {
        return 'recording';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(Alert $alert, array $event): array
    {
        $this->sentAlerts[] = $alert;

        return ['sent' => $this->delivers, 'channel' => $this->getIdentifier()];
    }
}

<?php

declare(strict_types=1);

namespace OliverThiele\OtAlerts\Service;

use OliverThiele\OtAlerts\Alert\Alert;
use OliverThiele\OtAlerts\Alert\AlertStatus;
use OliverThiele\OtAlerts\Channel\AlertChannelInterface;
use OliverThiele\OtAlerts\Domain\Repository\AlertEventRepository;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

class AlertManager
{
    private int $reminderInterval;

    public function __construct(
        private readonly AlertEventRepository $alertEventRepository,
        private readonly AlertChannelInterface $pushoverChannel,
        private readonly LoggerInterface $logger,
        ExtensionConfiguration $extensionConfiguration,
    ) {
        $configuration = $extensionConfiguration->get('ot_alerts');
        $reminderIntervalRaw = is_array($configuration) ? ($configuration['reminderInterval'] ?? 3600) : 3600;
        $this->reminderInterval = is_numeric($reminderIntervalRaw) ? (int)$reminderIntervalRaw : 3600;
    }

    /**
     * @return array{sent: bool, reason: string, channels: list<array{sent: bool, channel: string, httpStatus?: int, body?: string, error?: string}>}
     */
    public function notify(Alert $alert): array
    {
        $result = ['sent' => false, 'reason' => 'unknown', 'channels' => []];

        try {
            $event = $this->alertEventRepository->upsertEvent($alert);

            if ($event === []) {
                $result['reason'] = 'error';
                return $result;
            }

            if (!$this->shouldNotify($event)) {
                $result['reason'] = 'rate_limited';
                return $result;
            }

            $channels = $this->getChannels();

            if ($channels === []) {
                $result['reason'] = 'no_channels';
                return $result;
            }

            $status = is_string($event['status'] ?? null) ? $event['status'] : '';
            $result['reason'] = $status === AlertStatus::NEW->value ? 'new' : 'reminder';

            foreach ($channels as $channel) {
                $channelResult = $channel->send($alert, $event);
                $result['channels'][] = $channelResult;
                if ($channelResult['sent']) {
                    $result['sent'] = true;
                }
            }

            if ($result['sent']) {
                $uid = isset($event['uid']) && is_numeric($event['uid']) ? (int)$event['uid'] : 0;
                if ($uid > 0) {
                    $this->alertEventRepository->markNotified($uid);
                }
            }
        } catch (\Throwable $throwable) {
            $this->logger->error(
                'AlertManager::notify failed for {source}/{eventKey}: {message}',
                [
                    'source'    => $alert->source,
                    'eventKey'  => $alert->eventKey,
                    'message'   => $throwable->getMessage(),
                    'exception' => $throwable,
                ]
            );
            $result['reason'] = 'error';
        }

        return $result;
    }

    public function resolve(string $source, string $eventKey): void
    {
        try {
            $this->alertEventRepository->resolveEvent($source, $eventKey);
        } catch (\Throwable $throwable) {
            $this->logger->error(
                'AlertManager::resolve failed for {source}/{eventKey}: {message}',
                [
                    'source'    => $source,
                    'eventKey'  => $eventKey,
                    'message'   => $throwable->getMessage(),
                    'exception' => $throwable,
                ]
            );
        }
    }

    /** @param array<string, mixed> $event */
    private function shouldNotify(array $event): bool
    {
        $status = is_string($event['status'] ?? null) ? $event['status'] : '';
        $lastNotified = is_numeric($event['last_notified'] ?? null) ? (int)$event['last_notified'] : 0;

        if ($status === AlertStatus::NEW->value) {
            return true;
        }

        if ($status === AlertStatus::NOTIFIED->value && ($lastNotified + $this->reminderInterval) < time()) {
            return true;
        }

        return false;
    }

    /** @return AlertChannelInterface[] */
    private function getChannels(): array
    {
        $channels = [$this->pushoverChannel];

        return array_filter($channels, static fn(AlertChannelInterface $channel): bool => $channel->isConfigured());
    }
}

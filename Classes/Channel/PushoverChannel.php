<?php

declare(strict_types=1);

namespace OliverThiele\OtAlerts\Channel;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OliverThiele\OtAlerts\Alert\Alert;
use OliverThiele\OtAlerts\Alert\AlertSeverity;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

class PushoverChannel implements AlertChannelInterface
{
    private const API_URL = 'https://api.pushover.net/1/messages.json';

    // https://pushover.net/api#priority
    private const PRIORITY_LOW       = -1; // quiet notification
    private const PRIORITY_NORMAL    =  0; // default sound and vibration
    private const PRIORITY_HIGH      =  1; // bypasses quiet hours
    private const PRIORITY_EMERGENCY =  2; // repeated until acknowledged

    private const EMERGENCY_RETRY_MIN  = 30;
    private const EMERGENCY_EXPIRE_MAX = 10800;

    private int $emergencyRetry;
    private int $emergencyExpire;

    public function __construct(
        ExtensionConfiguration $extensionConfiguration,
        private readonly LoggerInterface $logger,
    ) {
        $configuration = $extensionConfiguration->get('ot_alerts');
        $configuration = is_array($configuration) ? $configuration : [];

        $emergencyRetryRaw  = $configuration['pushoverEmergencyRetry'] ?? 60;
        $emergencyExpireRaw = $configuration['pushoverEmergencyExpire'] ?? 3600;

        $this->emergencyRetry  = max(self::EMERGENCY_RETRY_MIN, is_numeric($emergencyRetryRaw) ? (int)$emergencyRetryRaw : 60);
        $this->emergencyExpire = min(self::EMERGENCY_EXPIRE_MAX, is_numeric($emergencyExpireRaw) ? (int)$emergencyExpireRaw : 3600);
    }

    public function getIdentifier(): string
    {
        return 'pushover';
    }

    public function isConfigured(): bool
    {
        return isset($_ENV['PUSHOVER_APP_TOKEN'], $_ENV['PUSHOVER_USER_KEY'])
            && $_ENV['PUSHOVER_APP_TOKEN'] !== ''
            && $_ENV['PUSHOVER_USER_KEY'] !== '';
    }

    /**
     * @param array<string, mixed> $event
     * @return array{sent: bool, channel: string, httpStatus?: int, body?: string, error?: string}
     */
    public function send(Alert $alert, array $event): array
    {
        if (!$this->isConfigured()) {
            $this->logger->warning('Pushover not configured — PUSHOVER_APP_TOKEN or PUSHOVER_USER_KEY missing');
            return ['sent' => false, 'channel' => $this->getIdentifier(), 'error' => 'not configured'];
        }

        $occurrenceCount = isset($event['occurrence_count']) && is_numeric($event['occurrence_count'])
            ? (int)$event['occurrence_count']
            : 1;

        $hostname = gethostname() ?: 'unknown';
        $title    = sprintf('[%s] %s @ %s', strtoupper($alert->severity->value), $alert->source, $hostname);
        $priority = $this->mapSeverityToPriority($alert->severity);

        $parameters = [
            'token'    => $_ENV['PUSHOVER_APP_TOKEN'],
            'user'     => $_ENV['PUSHOVER_USER_KEY'],
            'title'    => $title,
            'message'  => $this->buildHtmlMessage($alert, $occurrenceCount),
            'html'     => 1,
            'priority' => $priority,
        ];

        // Emergency priority requires retry + expire
        if ($priority === self::PRIORITY_EMERGENCY) {
            $parameters['retry']  = $this->emergencyRetry;
            $parameters['expire'] = $this->emergencyExpire;
        }

        // Optional tappable link button (from context['url'])
        $contextUrl = is_string($alert->context['url'] ?? null) ? $alert->context['url'] : '';
        if ($contextUrl !== '') {
            $parameters['url']       = $contextUrl;
            $parameters['url_title'] = 'Open page';
        }

        try {
            $httpClient = new Client();
            $response = $httpClient->post(self::API_URL, ['form_params' => $parameters]);
            $body = $response->getBody()->getContents();

            return [
                'sent'       => true,
                'channel'    => $this->getIdentifier(),
                'httpStatus' => $response->getStatusCode(),
                'body'       => $body,
            ];
        } catch (GuzzleException $exception) {
            $this->logger->error(
                'Pushover notification failed: {message}',
                ['message' => $exception->getMessage(), 'exception' => $exception]
            );

            return [
                'sent'    => false,
                'channel' => $this->getIdentifier(),
                'error'   => $exception->getMessage(),
            ];
        }
    }

    private function buildHtmlMessage(Alert $alert, int $occurrenceCount): string
    {
        $lines = [
            sprintf('<b>%s</b>', htmlspecialchars($alert->eventKey)),
            '',
            htmlspecialchars($alert->message),
        ];

        if ($occurrenceCount > 1) {
            $lines[] = '';
            $lines[] = sprintf('<i>occurrence #%d</i>', $occurrenceCount);
        }

        return implode("\n", $lines);
    }

    private function mapSeverityToPriority(AlertSeverity $severity): int
    {
        return match ($severity) {
            AlertSeverity::INFO     => self::PRIORITY_LOW,
            AlertSeverity::WARNING  => self::PRIORITY_NORMAL,
            AlertSeverity::ERROR    => self::PRIORITY_HIGH,
            AlertSeverity::CRITICAL => self::PRIORITY_EMERGENCY,
        };
    }
}

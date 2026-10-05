<?php

declare(strict_types=1);

namespace OliverThiele\OtAlerts\Channel;

use OliverThiele\OtAlerts\Alert\Alert;
use OliverThiele\OtAlerts\Alert\AlertSeverity;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

class PushoverChannel implements AlertChannelInterface
{
    private const string API_URL = 'https://api.pushover.net/1/messages.json';

    // https://pushover.net/api#priority
    private const int PRIORITY_LOW = -1; // quiet notification
    private const int PRIORITY_NORMAL = 0; // default sound and vibration
    private const int PRIORITY_HIGH = 1; // bypasses quiet hours
    private const int PRIORITY_EMERGENCY = 2; // repeated until acknowledged

    private const int EMERGENCY_RETRY_MIN = 30;
    private const int EMERGENCY_EXPIRE_MAX = 10800;

    // https://pushover.net/api#limits — longer values are rejected.
    private const int MAXIMUM_MESSAGE_LENGTH = 1024;
    private const int MAXIMUM_TITLE_LENGTH = 250;
    private const int MAXIMUM_URL_LENGTH = 512;
    private const int MAXIMUM_EVENT_KEY_LENGTH = 200;

    /**
     * Seconds the request may take. An alert is often raised inside a frontend
     * request; a slow or unreachable Pushover API must not keep it waiting.
     */
    private const int REQUEST_TIMEOUT = 5;

    private int $emergencyRetry;
    private int $emergencyExpire;

    public function __construct(
        ExtensionConfiguration $extensionConfiguration,
        private readonly RequestFactory $requestFactory,
        private readonly LoggerInterface $logger,
    ) {
        $configuration = $extensionConfiguration->get('ot_alerts');
        $configuration = is_array($configuration) ? $configuration : [];

        $emergencyRetryRaw = $configuration['pushoverEmergencyRetry'] ?? 60;
        $emergencyExpireRaw = $configuration['pushoverEmergencyExpire'] ?? 3600;

        $this->emergencyRetry = max(self::EMERGENCY_RETRY_MIN, is_numeric($emergencyRetryRaw) ? (int)$emergencyRetryRaw : 60);
        $this->emergencyExpire = min(self::EMERGENCY_EXPIRE_MAX, is_numeric($emergencyExpireRaw) ? (int)$emergencyExpireRaw : 3600);
    }

    public function getIdentifier(): string
    {
        return 'pushover';
    }

    public function isConfigured(): bool
    {
        return PushoverCredentials::fromEnvironment()->isComplete();
    }

    /**
     * @param array<string, mixed> $event
     * @return array{sent: bool, channel: string, httpStatus?: int, body?: string, error?: string}
     */
    public function send(Alert $alert, array $event): array
    {
        $credentials = PushoverCredentials::fromEnvironment();
        if (!$credentials->isComplete()) {
            $this->logger->warning('Pushover not configured — PUSHOVER_APP_TOKEN or PUSHOVER_USER_KEY missing');
            return ['sent' => false, 'channel' => $this->getIdentifier(), 'error' => 'not configured'];
        }

        $occurrenceCount = isset($event['occurrence_count']) && is_numeric($event['occurrence_count'])
            ? (int)$event['occurrence_count']
            : 1;

        $hostname = gethostname() ?: 'unknown';
        $title = sprintf('[%s] %s @ %s', strtoupper($alert->severity->value), $alert->source, $hostname);
        $priority = $this->mapSeverityToPriority($alert->severity);

        $parameters = [
            'token' => $credentials->appToken,
            'user' => $credentials->userKey,
            'title' => mb_substr($title, 0, self::MAXIMUM_TITLE_LENGTH),
            'message' => $this->buildHtmlMessage($alert, $occurrenceCount),
            'html' => 1,
            'priority' => $priority,
        ];

        // Emergency priority requires retry + expire
        if ($priority === self::PRIORITY_EMERGENCY) {
            $parameters['retry'] = $this->emergencyRetry;
            $parameters['expire'] = $this->emergencyExpire;
        }

        // Optional tappable link button (from context['url'])
        $contextUrl = $this->buildUrl($alert);
        if ($contextUrl !== '') {
            $parameters['url'] = $contextUrl;
            $parameters['url_title'] = 'Open page';
        }

        try {
            $response = $this->requestFactory->request(self::API_URL, 'POST', [
                'form_params' => $parameters,
                'timeout' => self::REQUEST_TIMEOUT,
                'connect_timeout' => self::REQUEST_TIMEOUT,
                'http_errors' => false,
            ]);
        } catch (\Throwable $throwable) {
            $this->logger->error(
                'Pushover notification failed: {message}',
                ['message' => $throwable->getMessage(), 'exception' => $throwable]
            );

            return [
                'sent' => false,
                'channel' => $this->getIdentifier(),
                'error' => $throwable->getMessage(),
            ];
        }

        $httpStatus = $response->getStatusCode();
        $body = (string)$response->getBody();
        // Accepted only with HTTP 200 and "status": 1; a 4xx names the invalid parameters in "errors".
        $decoded = json_decode($body, true);
        $accepted = $httpStatus === 200 && is_array($decoded) && ($decoded['status'] ?? null) === 1;
        if (!$accepted) {
            $this->logger->error(
                'Pushover rejected the notification with HTTP {httpStatus}: {body}',
                ['httpStatus' => $httpStatus, 'body' => $body]
            );

            return [
                'sent' => false,
                'channel' => $this->getIdentifier(),
                'httpStatus' => $httpStatus,
                'body' => $body,
                'error' => sprintf('rejected with HTTP %d', $httpStatus),
            ];
        }

        return [
            'sent' => true,
            'channel' => $this->getIdentifier(),
            'httpStatus' => $httpStatus,
            'body' => $body,
        ];
    }

    /**
     * The event key in bold, the message, and — for a repeating condition —
     * the occurrence counter. The message is shortened so that the whole text,
     * markup included, stays within the Pushover limit.
     */
    private function buildHtmlMessage(Alert $alert, int $occurrenceCount): string
    {
        $eventKey = mb_substr($alert->eventKey, 0, self::MAXIMUM_EVENT_KEY_LENGTH);
        // The counter describes a condition that keeps repeating. An unthrottled
        // notification is a new event every time, so the line would be misleading.
        $footer = $occurrenceCount > 1 && $alert->throttle ? sprintf("\n\n<i>occurrence #%d</i>", $occurrenceCount) : '';
        $compose = static fn(string $message): string => sprintf('<b>%s</b>', htmlspecialchars($eventKey)) . "\n\n" . htmlspecialchars($message) . $footer;

        $message = $alert->message;
        $html = $compose($message);
        // Escaping makes the text longer than the characters it consists of, so
        // the cut is repeated until the result fits; each round removes at least
        // the overflow.
        while (mb_strlen($html) > self::MAXIMUM_MESSAGE_LENGTH && $message !== '') {
            $overflow = mb_strlen($html) - self::MAXIMUM_MESSAGE_LENGTH;
            $message = mb_substr($message, 0, max(0, mb_strlen($message) - $overflow - 1));
            $html = $compose($message === '' ? '' : $message . '…');
        }

        return $html;
    }

    /**
     * The link button of context['url'], or '' when there is none. Only http
     * and https URLs within the length limit are passed on.
     */
    private function buildUrl(Alert $alert): string
    {
        $url = $alert->context['url'] ?? null;
        if (!is_string($url) || $url === '') {
            return '';
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true) || mb_strlen($url) > self::MAXIMUM_URL_LENGTH) {
            $this->logger->notice('Pushover link button skipped: the URL is not http(s) or longer than {maximum} characters', ['maximum' => self::MAXIMUM_URL_LENGTH]);
            return '';
        }

        return $url;
    }

    private function mapSeverityToPriority(AlertSeverity $severity): int
    {
        return match ($severity) {
            AlertSeverity::INFO => self::PRIORITY_LOW,
            AlertSeverity::NOTICE => self::PRIORITY_NORMAL,
            AlertSeverity::WARNING => self::PRIORITY_NORMAL,
            AlertSeverity::ERROR => self::PRIORITY_HIGH,
            AlertSeverity::CRITICAL => self::PRIORITY_EMERGENCY,
        };
    }
}

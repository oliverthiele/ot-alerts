<?php

declare(strict_types=1);

namespace OliverThiele\OtAlerts\Tests\Unit\Channel;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use OliverThiele\OtAlerts\Alert\Alert;
use OliverThiele\OtAlerts\Alert\AlertSeverity;
use OliverThiele\OtAlerts\Channel\PushoverChannel;
use OliverThiele\OtAlerts\Channel\PushoverCredentials;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class PushoverChannelTest extends UnitTestCase
{
    private const string ACCEPTED = '{"status":1,"request":"abc"}';

    /** @var array<string, mixed> options of the last request */
    private array $sentOptions = [];

    /** @var array<string, string|false> the process environment before the test */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();
        // A development container may hold real credentials in its environment.
        foreach ([PushoverCredentials::APP_TOKEN_VARIABLE, PushoverCredentials::USER_KEY_VARIABLE] as $name) {
            $this->originalEnvironment[$name] = getenv($name);
            putenv($name);
            unset($_ENV[$name]);
        }
        $_ENV[PushoverCredentials::APP_TOKEN_VARIABLE] = 'token';
        $_ENV[PushoverCredentials::USER_KEY_VARIABLE] = 'user';
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
            unset($_ENV[$name]);
        }
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: AlertSeverity, 1: int}>
     */
    public static function severityProvider(): array
    {
        return [
            'info is quiet' => [AlertSeverity::INFO, -1],
            'notice is normal' => [AlertSeverity::NOTICE, 0],
            'warning is normal' => [AlertSeverity::WARNING, 0],
            'error bypasses quiet hours' => [AlertSeverity::ERROR, 1],
            'critical is an emergency' => [AlertSeverity::CRITICAL, 2],
        ];
    }

    #[Test]
    #[DataProvider('severityProvider')]
    public function severityMapsToThePushoverPriority(AlertSeverity $severity, int $priority): void
    {
        $this->channel()->send($this->alert(severity: $severity), []);

        self::assertSame($priority, $this->parameter('priority'));
    }

    #[Test]
    public function emergencyRetryAndExpireAreKeptWithinThePushoverLimits(): void
    {
        $this->channel(['pushoverEmergencyRetry' => 10, 'pushoverEmergencyExpire' => 99999])->send($this->alert(severity: AlertSeverity::CRITICAL), []);

        self::assertSame(30, $this->parameter('retry'));
        self::assertSame(10800, $this->parameter('expire'));
    }

    #[Test]
    public function requestHasATimeout(): void
    {
        $this->channel()->send($this->alert(), []);

        self::assertSame(5, $this->sentOptions['timeout'] ?? null);
        self::assertFalse($this->sentOptions['http_errors'] ?? null);
    }

    #[Test]
    public function longMessageIsShortenedToThePushoverLimit(): void
    {
        $this->channel()->send($this->alert(message: str_repeat('x', 5000)), []);

        $message = $this->parameter('message');
        self::assertIsString($message);
        self::assertLessThanOrEqual(1024, mb_strlen($message));
        self::assertStringEndsWith('…', $message);
    }

    #[Test]
    public function messageThatGrowsByEscapingStillFits(): void
    {
        $this->channel()->send($this->alert(message: str_repeat('<&>', 1000)), []);

        $message = $this->parameter('message');
        self::assertIsString($message);
        self::assertLessThanOrEqual(1024, mb_strlen($message));
        self::assertStringNotContainsString('<&>', $message);
    }

    #[Test]
    public function occurrenceCounterIsShownForARepeatingThrottledEventOnly(): void
    {
        $this->channel()->send($this->alert(), ['occurrence_count' => 3]);
        self::assertStringContainsString('occurrence #3', (string)$this->parameter('message'));

        $this->channel()->send($this->alert(throttle: false), ['occurrence_count' => 3]);
        self::assertStringNotContainsString('occurrence', (string)$this->parameter('message'));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function urlProvider(): array
    {
        return [
            'https' => ['https://www.example.com/page/', true],
            'javascript' => ['javascript:alert(1)', false],
            'too long' => ['https://www.example.com/' . str_repeat('a', 600), false],
        ];
    }

    #[Test]
    #[DataProvider('urlProvider')]
    public function onlyHttpUrlsWithinTheLimitBecomeALinkButton(string $url, bool $expected): void
    {
        $this->channel()->send($this->alert(context: ['url' => $url]), []);

        self::assertSame($expected, $this->parameter('url') === $url);
    }

    #[Test]
    public function acceptedOnlyWithStatusOne(): void
    {
        self::assertTrue($this->channel()->send($this->alert(), [])['sent']);
        self::assertFalse($this->channel(answer: new Response('php://temp', 400))->send($this->alert(), [])['sent']);
        self::assertFalse($this->channel(answer: $this->response(200, '{"status":0}'))->send($this->alert(), [])['sent']);
    }

    #[Test]
    public function transportFailureIsReportedNotThrown(): void
    {
        $result = $this->channel(answer: new ConnectException('Connection refused', new Request('POST', 'https://api.pushover.net/')))->send($this->alert(), []);

        self::assertFalse($result['sent']);
        self::assertSame('Connection refused', $result['error'] ?? null);
    }

    #[Test]
    public function missingCredentialsSendNothing(): void
    {
        unset($_ENV[PushoverCredentials::USER_KEY_VARIABLE]);

        $result = $this->channel()->send($this->alert(), []);

        self::assertFalse($result['sent']);
        self::assertSame([], $this->sentOptions);
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function channel(array $configuration = [], Response|\Throwable|null $answer = null): PushoverChannel
    {
        $answer ??= $this->response(200, self::ACCEPTED);
        $this->sentOptions = [];
        $requestFactory = self::createStub(RequestFactory::class);
        $requestFactory->method('request')->willReturnCallback(
            function (string $url, string $method, array $options) use ($answer): Response {
                $this->sentOptions = $options;
                if ($answer instanceof \Throwable) {
                    throw $answer;
                }
                return $answer;
            },
        );
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($configuration);

        return new PushoverChannel($extensionConfiguration, $requestFactory, new NullLogger());
    }

    /**
     * @param array<string, mixed> $context
     */
    private function alert(
        string $message = 'Could not connect',
        AlertSeverity $severity = AlertSeverity::ERROR,
        array $context = [],
        bool $throttle = true,
    ): Alert {
        return new Alert('my_extension', 'api.connection.failed', $message, $severity, $context, null, $throttle);
    }

    private function parameter(string $name): mixed
    {
        $formParameters = $this->sentOptions['form_params'] ?? [];

        return is_array($formParameters) ? ($formParameters[$name] ?? null) : null;
    }

    private function response(int $status, string $body): Response
    {
        $response = new Response('php://temp', $status);
        $response->getBody()->write($body);
        $response->getBody()->rewind();

        return $response;
    }
}

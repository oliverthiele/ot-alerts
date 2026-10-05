<?php

declare(strict_types=1);

namespace OliverThiele\OtAlerts\Tests\Functional\Service;

use OliverThiele\OtAlerts\Alert\Alert;
use OliverThiele\OtAlerts\Alert\AlertSeverity;
use OliverThiele\OtAlerts\Domain\Repository\AlertEventRepository;
use OliverThiele\OtAlerts\Service\AlertManager;
use OliverThiele\OtAlerts\Tests\Functional\Fixtures\RecordingChannel;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The rate limit and the event states, end to end against the database.
 */
final class AlertManagerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['oliverthiele/ot-alerts'];

    private RecordingChannel $channel;
    private AlertEventRepository $repository;
    private AlertManager $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->channel = new RecordingChannel();
        $this->repository = new AlertEventRepository($this->get(ConnectionPool::class));
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['reminderInterval' => 3600]);
        $this->subject = new AlertManager($this->repository, $this->channel, new NullLogger(), $extensionConfiguration);
    }

    #[Test]
    public function firstOccurrenceIsSentAndRepetitionsAreHeldBack(): void
    {
        self::assertSame('new', $this->subject->notify($this->alert())['reason']);
        self::assertSame('rate_limited', $this->subject->notify($this->alert())['reason']);
        self::assertCount(1, $this->channel->sentAlerts);
    }

    #[Test]
    public function reminderIsSentOnceTheIntervalIsOver(): void
    {
        $this->subject->notify($this->alert());
        $this->moveLastNotificationBack(3601);

        $result = $this->subject->notify($this->alert());

        self::assertSame('reminder', $result['reason']);
        self::assertTrue($result['sent']);
    }

    #[Test]
    public function perAlertIntervalOverridesTheConfiguredOne(): void
    {
        $this->subject->notify($this->alert(reminderInterval: 60));
        $this->moveLastNotificationBack(61);

        self::assertSame('reminder', $this->subject->notify($this->alert(reminderInterval: 60))['reason']);
    }

    #[Test]
    public function transactionalNotificationIsAlwaysSent(): void
    {
        $this->subject->notify($this->alert(throttle: false));
        $result = $this->subject->notify($this->alert(throttle: false));

        self::assertSame('notification', $result['reason']);
        self::assertCount(2, $this->channel->sentAlerts);
    }

    #[Test]
    public function undeliveredAlertIsTriedAgainOnTheNextOccurrence(): void
    {
        $this->channel->delivers = false;
        self::assertFalse($this->subject->notify($this->alert())['sent']);

        $this->channel->delivers = true;
        $result = $this->subject->notify($this->alert());

        self::assertTrue($result['sent']);
        self::assertSame('new', $result['reason']);
    }

    #[Test]
    public function resolvedEventIsSentAgainAtOnceWhenItReturns(): void
    {
        $this->subject->notify($this->alert());
        $this->subject->resolve('my_extension', 'api.failed');

        $result = $this->subject->notify($this->alert());

        self::assertSame('new', $result['reason']);
        self::assertCount(2, $this->channel->sentAlerts);
        self::assertSame(1, (int)$this->repository->findBySourceAndEventKey('my_extension', 'api.failed')['occurrence_count']);
    }

    private function alert(bool $throttle = true, ?int $reminderInterval = null): Alert
    {
        return new Alert('my_extension', 'api.failed', 'Could not connect', AlertSeverity::ERROR, [], $reminderInterval, $throttle);
    }

    private function moveLastNotificationBack(int $seconds): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable(AlertEventRepository::TABLE)
            ->update(AlertEventRepository::TABLE, ['last_notified' => time() - $seconds], ['event_key' => 'api.failed']);
    }
}

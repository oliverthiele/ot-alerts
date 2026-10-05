<?php

declare(strict_types=1);

namespace OliverThiele\OtAlerts\Tests\Functional\Domain\Repository;

use OliverThiele\OtAlerts\Alert\Alert;
use OliverThiele\OtAlerts\Alert\AlertSeverity;
use OliverThiele\OtAlerts\Alert\AlertStatus;
use OliverThiele\OtAlerts\Domain\Repository\AlertEventRepository;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Runs on SQLite: the repository must not depend on MySQL-only SQL.
 */
final class AlertEventRepositoryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['oliverthiele/ot-alerts'];

    private AlertEventRepository $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new AlertEventRepository($this->get(ConnectionPool::class));
    }

    #[Test]
    public function firstOccurrenceCreatesANewEvent(): void
    {
        $event = $this->subject->upsertEvent($this->alert('first'));

        self::assertSame(AlertStatus::NEW->value, $event['status']);
        self::assertSame(1, (int)$event['occurrence_count']);
        self::assertSame('first', $event['last_message']);
    }

    #[Test]
    public function furtherOccurrencesAreCountedOnTheSameRow(): void
    {
        $this->subject->upsertEvent($this->alert('first'));
        $this->subject->upsertEvent($this->alert('second', AlertSeverity::CRITICAL));
        $event = $this->subject->upsertEvent($this->alert('third'));

        self::assertSame(3, (int)$event['occurrence_count']);
        self::assertSame('third', $event['last_message']);
        self::assertSame(1, $this->countRows());
    }

    #[Test]
    public function resolvedEventStartsOverOnItsNextOccurrence(): void
    {
        $this->subject->upsertEvent($this->alert('first'));
        $this->subject->upsertEvent($this->alert('second'));
        $this->subject->resolveEvent('my_extension', 'api.failed');

        $event = $this->subject->upsertEvent($this->alert('again'));

        self::assertSame(AlertStatus::NEW->value, $event['status']);
        self::assertSame(1, (int)$event['occurrence_count']);
    }

    #[Test]
    public function newEventIsClaimedOnlyOnce(): void
    {
        $uid = (int)$this->subject->upsertEvent($this->alert('first'))['uid'];

        self::assertTrue($this->subject->claimNotification($uid, time() - 3600));
        self::assertFalse($this->subject->claimNotification($uid, time() - 3600), 'A second process must not claim it again.');
    }

    #[Test]
    public function notifiedEventIsClaimedAgainOnlyAfterTheInterval(): void
    {
        $uid = (int)$this->subject->upsertEvent($this->alert('first'))['uid'];
        $this->subject->claimNotification($uid, time() - 3600);

        // Interval not over: last notified now, threshold an hour ago.
        self::assertFalse($this->subject->claimNotification($uid, time() - 3600));
        // Interval over: the threshold lies after the last notification.
        self::assertTrue($this->subject->claimNotification($uid, time() + 1));
    }

    #[Test]
    public function releasedClaimRestoresTheFormerState(): void
    {
        $event = $this->subject->upsertEvent($this->alert('first'));
        $uid = (int)$event['uid'];
        $this->subject->claimNotification($uid, time() - 3600);

        $this->subject->releaseClaim($uid, AlertStatus::NEW->value, 0);

        $event = $this->subject->findBySourceAndEventKey('my_extension', 'api.failed');
        self::assertSame(AlertStatus::NEW->value, $event['status']);
        self::assertSame(0, (int)$event['last_notified']);
    }

    private function alert(string $message, AlertSeverity $severity = AlertSeverity::ERROR): Alert
    {
        return new Alert('my_extension', 'api.failed', $message, $severity);
    }

    private function countRows(): int
    {
        return (int)$this->get(ConnectionPool::class)->getConnectionForTable(AlertEventRepository::TABLE)->count('*', AlertEventRepository::TABLE, []);
    }
}

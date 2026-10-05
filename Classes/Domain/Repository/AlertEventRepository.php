<?php

declare(strict_types=1);

namespace OliverThiele\OtAlerts\Domain\Repository;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use OliverThiele\OtAlerts\Alert\Alert;
use OliverThiele\OtAlerts\Alert\AlertStatus;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * One row per source and event key. Every statement is plain QueryBuilder
 * SQL, so the table works on every database TYPO3 supports.
 */
class AlertEventRepository
{
    public const string TABLE = 'tx_otalerts_events';
    private const int MAXIMUM_MESSAGE_LENGTH = 2000;

    public function __construct(private readonly ConnectionPool $connectionPool)
    {
    }

    /**
     * Records one occurrence and returns the row afterwards. A resolved event
     * starts over: status "new", its occurrences counted from one again.
     *
     * @return array<string, mixed> empty when the row could not be written
     */
    public function upsertEvent(Alert $alert): array
    {
        $now = time();
        $this->reopenResolvedEvent($alert, $now);

        if ($this->countOccurrence($alert, $now) === 0) {
            try {
                $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
                    'pid' => 0,
                    'source' => $alert->source,
                    'event_key' => $alert->eventKey,
                    'severity' => $alert->severity->value,
                    'status' => AlertStatus::NEW->value,
                    'first_occurrence' => $now,
                    'last_occurrence' => $now,
                    'last_notified' => 0,
                    'occurrence_count' => 1,
                    'last_message' => mb_substr($alert->message, 0, self::MAXIMUM_MESSAGE_LENGTH),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Another process inserted the row in between; count this occurrence there.
                $this->countOccurrence($alert, $now);
            }
        }

        return $this->findBySourceAndEventKey($alert->source, $alert->eventKey);
    }

    /**
     * Takes the right to notify about an event: only one of several processes
     * reporting the same event at the same time wins. A new event can be
     * claimed at once, a notified one only when it was last notified before
     * $notifiedBefore.
     *
     * @return bool true when this call claimed the event
     */
    public function claimNotification(int $uid, int $notifiedBefore): bool
    {
        $queryBuilder = $this->createQueryBuilder();
        $affectedRows = $queryBuilder
            ->update(self::TABLE)
            ->set('status', AlertStatus::NOTIFIED->value)
            ->set('last_notified', time(), true, ParameterType::INTEGER)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter(AlertStatus::NEW->value)),
                    $queryBuilder->expr()->and(
                        $queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter(AlertStatus::NOTIFIED->value)),
                        $queryBuilder->expr()->lt('last_notified', $queryBuilder->createNamedParameter($notifiedBefore, ParameterType::INTEGER)),
                    ),
                ),
            )
            ->executeStatement();

        return $affectedRows === 1;
    }

    /**
     * Gives a claim back when no channel delivered the notification, so the
     * next occurrence tries again instead of waiting for the reminder interval.
     */
    public function releaseClaim(int $uid, string $previousStatus, int $previousLastNotified): void
    {
        $queryBuilder = $this->createQueryBuilder();
        $queryBuilder
            ->update(self::TABLE)
            ->set('status', $previousStatus)
            ->set('last_notified', $previousLastNotified, true, ParameterType::INTEGER)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter(AlertStatus::NOTIFIED->value)),
            )
            ->executeStatement();
    }

    public function markNotified(int $uid): void
    {
        $queryBuilder = $this->createQueryBuilder();
        $queryBuilder
            ->update(self::TABLE)
            ->set('status', AlertStatus::NOTIFIED->value)
            ->set('last_notified', time(), true, ParameterType::INTEGER)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeStatement();
    }

    public function resolveEvent(string $source, string $eventKey): void
    {
        $queryBuilder = $this->createQueryBuilder();
        $queryBuilder
            ->update(self::TABLE)
            ->set('status', AlertStatus::RESOLVED->value)
            ->where(
                $queryBuilder->expr()->eq('source', $queryBuilder->createNamedParameter($source)),
                $queryBuilder->expr()->eq('event_key', $queryBuilder->createNamedParameter($eventKey)),
                $queryBuilder->expr()->neq('status', $queryBuilder->createNamedParameter(AlertStatus::RESOLVED->value))
            )
            ->executeStatement();
    }

    /** @return array<string, mixed> */
    public function findBySourceAndEventKey(string $source, string $eventKey): array
    {
        $queryBuilder = $this->createQueryBuilder();

        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('source', $queryBuilder->createNamedParameter($source)),
                $queryBuilder->expr()->eq('event_key', $queryBuilder->createNamedParameter($eventKey))
            )
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : [];
    }

    private function reopenResolvedEvent(Alert $alert, int $now): void
    {
        $queryBuilder = $this->createQueryBuilder();
        $queryBuilder
            ->update(self::TABLE)
            ->set('status', AlertStatus::NEW->value)
            ->set('occurrence_count', 0, true, ParameterType::INTEGER)
            ->set('first_occurrence', $now, true, ParameterType::INTEGER)
            ->where(
                ...$this->eventConstraints($queryBuilder, $alert),
                ...[$queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter(AlertStatus::RESOLVED->value))],
            )
            ->executeStatement();
    }

    /**
     * @return int number of rows updated — 0 when the event has no row yet
     */
    private function countOccurrence(Alert $alert, int $now): int
    {
        $queryBuilder = $this->createQueryBuilder();

        // The increment runs in the database, so concurrent occurrences are all counted.
        return $queryBuilder
            ->update(self::TABLE)
            ->set('occurrence_count', $queryBuilder->quoteIdentifier('occurrence_count') . ' + 1', false)
            ->set('last_occurrence', $now, true, ParameterType::INTEGER)
            ->set('severity', $alert->severity->value)
            ->set('last_message', mb_substr($alert->message, 0, self::MAXIMUM_MESSAGE_LENGTH))
            ->where(...$this->eventConstraints($queryBuilder, $alert))
            ->executeStatement();
    }

    /**
     * @return list<string>
     */
    private function eventConstraints(QueryBuilder $queryBuilder, Alert $alert): array
    {
        return [
            $queryBuilder->expr()->eq('source', $queryBuilder->createNamedParameter($alert->source)),
            $queryBuilder->expr()->eq('event_key', $queryBuilder->createNamedParameter($alert->eventKey)),
        ];
    }

    private function createQueryBuilder(): QueryBuilder
    {
        // The table has no enable fields or soft delete.
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder;
    }
}

<?php

declare(strict_types=1);

namespace OliverThiele\OtAlerts\Domain\Repository;

use OliverThiele\OtAlerts\Alert\Alert;
use OliverThiele\OtAlerts\Alert\AlertStatus;
use TYPO3\CMS\Core\Database\ConnectionPool;

class AlertEventRepository
{
    private const TABLE = 'tx_otalerts_events';

    public function __construct(private readonly ConnectionPool $connectionPool)
    {
    }

    /** @return array<string, mixed> */
    public function upsertEvent(Alert $alert): array
    {
        $now = time();

        $this->connectionPool->getConnectionForTable(self::TABLE)->executeStatement(
            'INSERT INTO ' . self::TABLE . '
                (source, event_key, severity, status, first_occurrence, last_occurrence, last_notified, occurrence_count, last_message)
             VALUES
                (:source, :eventKey, :severity, :status, :now, :now, 0, 1, :message)
             ON DUPLICATE KEY UPDATE
                severity         = VALUES(severity),
                last_occurrence  = VALUES(last_occurrence),
                occurrence_count = occurrence_count + 1,
                last_message     = VALUES(last_message),
                status           = IF(status = :resolved, :statusNew, status)',
            [
                'source'   => $alert->source,
                'eventKey' => $alert->eventKey,
                'severity' => $alert->severity->value,
                'status'   => AlertStatus::NEW->value,
                'now'      => $now,
                'message'  => mb_substr($alert->message, 0, 2000),
                'resolved' => AlertStatus::RESOLVED->value,
                'statusNew' => AlertStatus::NEW->value,
            ]
        );

        return $this->findBySourceAndEventKey($alert->source, $alert->eventKey);
    }

    public function markNotified(int $uid): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            [
                'status'        => AlertStatus::NOTIFIED->value,
                'last_notified' => time(),
            ],
            ['uid' => $uid]
        );
    }

    public function resolveEvent(string $source, string $eventKey): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
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
    private function findBySourceAndEventKey(string $source, string $eventKey): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

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
}

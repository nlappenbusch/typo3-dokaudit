<?php

declare(strict_types=1);

namespace DokAudit\Typo3\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Zustand je Datei (tx_dokaudit_file). Status:
 *  new       – wartet auf Übermittlung
 *  submitted – an DokAudit übergeben, Verarbeitung läuft
 *  replaced  – durch die korrigierte Fassung ersetzt (Original im Sicherungsordner)
 *  checked   – geprüft, nicht ersetzt (Modus „nur prüfen“ oder keine Verbesserung)
 *  waiting   – DokAudit wartet auf Guthaben
 *  error     – Fehler (siehe message)
 *  restored  – Original wiederhergestellt (wird nicht erneut eingereiht)
 *  skipped   – zu groß / ausgeschlossen
 */
final class FileStateRepository
{
    public const TABLE = 'tx_dokaudit_file';

    public function __construct(private readonly ConnectionPool $connectionPool) {}

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }

    /** @return array<string, mixed>|null */
    public function findByFile(int $fileUid): ?array
    {
        $row = $this->connection()->select(['*'], self::TABLE, ['file' => $fileUid])->fetchAssociative();
        return $row ?: null;
    }

    /**
     * @param string[] $statuses
     * @return array<int, array<string, mixed>>
     */
    public function findByStatus(array $statuses, int $limit = 50): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return $qb->select('*')->from(self::TABLE)
            ->where($qb->expr()->in('status', $qb->createNamedParameter($statuses, Connection::PARAM_STR_ARRAY)))
            ->orderBy('tstamp', 'ASC')
            ->setMaxResults($limit)
            ->executeQuery()->fetchAllAssociative();
    }

    /** @return array<int, array<string, mixed>> */
    public function findRecent(int $limit = 200): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return $qb->select('*')->from(self::TABLE)->orderBy('tstamp', 'DESC')
            ->setMaxResults($limit)->executeQuery()->fetchAllAssociative();
    }

    /** @return array<string, int|float|null> */
    public function stats(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $qb->select('status')->addSelectLiteral('COUNT(*) AS n')->from(self::TABLE)
            ->groupBy('status')->executeQuery()->fetchAllAssociative();
        $out = ['total' => 0];
        foreach ($rows as $r) {
            $out[(string)$r['status']] = (int)$r['n'];
            $out['total'] += (int)$r['n'];
        }
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $avg = $qb->selectLiteral('AVG(score_before) AS b', 'AVG(score_after) AS a')->from(self::TABLE)
            ->where($qb->expr()->eq('status', $qb->createNamedParameter('replaced')))
            ->executeQuery()->fetchAssociative() ?: [];
        $out['avg_before'] = isset($avg['b']) ? (int)round((float)$avg['b']) : null;
        $out['avg_after'] = isset($avg['a']) ? (int)round((float)$avg['a']) : null;
        return $out;
    }

    /** @param array<string, mixed> $fields */
    public function upsert(int $fileUid, array $fields): void
    {
        $fields['tstamp'] = time();
        if ($this->findByFile($fileUid) === null) {
            $this->connection()->insert(self::TABLE, $fields + ['file' => $fileUid, 'crdate' => time()]);
        } else {
            $this->connection()->update(self::TABLE, $fields, ['file' => $fileUid]);
        }
    }

    public function markNew(int $fileUid, int $storage, string $identifier, string $sha1): void
    {
        $this->upsert($fileUid, [
            'storage' => $storage, 'identifier' => mb_substr($identifier, 0, 1024), 'status' => 'new',
            'message' => '', 'document_id' => '', 'sha1_original' => $sha1, 'sha1_fixed' => '',
            'score_before' => null, 'score_after' => null,
        ]);
    }

    public function delete(int $fileUid): void
    {
        $this->connection()->delete(self::TABLE, ['file' => $fileUid]);
    }
}

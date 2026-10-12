<?php

declare(strict_types=1);

namespace DokAudit\Typo3\Service;

use DokAudit\Typo3\Api\DokAuditClient;
use DokAudit\Typo3\Api\DokAuditException;
use DokAudit\Typo3\Domain\Repository\FileStateRepository;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\Index\Indexer;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Kern der Erweiterung: PDFs der Dateiliste finden, an DokAudit übergeben, Ergebnis
 * abholen und die Datei IN PLACE ersetzen (ResourceStorage::replaceFile behält die
 * sys_file-UID — alle Verweise, Links und Inhaltselemente bleiben gültig).
 *
 * Ablauf je Abgleich (dokaudit:sync bzw. Backend-Modul):
 *   1. discover()  neue/geänderte PDFs als „new“ eintragen
 *   2. submit()    „new“ hochladen (Vollautomatik)        -> „submitted“
 *   3. collect()   „submitted“ abfragen; fertig -> apply()  -> „replaced“ | „checked“
 * Das Original landet vorher im Sicherungsordner und lässt sich mit restore()
 * wiederherstellen.
 */
final class PdfOptimizer
{
    /** Während wir selbst ersetzen, darf der Upload-Listener nicht erneut einreihen. */
    public static bool $replacing = false;

    public function __construct(
        private readonly DokAuditClient $client,
        private readonly FileStateRepository $states,
        private readonly ResourceFactory $resourceFactory,
        private readonly ConnectionPool $connectionPool,
        private readonly StorageRepository $storageRepository,
    ) {}

    /**
     * Dateiindex der Speicher aktualisieren (wie die Scheduler-Aufgabe „Dateiindex
     * aktualisieren“), damit auch per FTP/Deployment abgelegte PDFs gefunden werden.
     */
    public function indexStorages(): int
    {
        $s = $this->settings();
        $n = 0;
        foreach ($this->storageRepository->findAll() as $storage) {
            if (($s['storages'] !== [] && !in_array($storage->getUid(), $s['storages'], true))
                || ($s['storages'] === [] && $storage->getDriverType() !== 'Local')
                || !$storage->isOnline()) {
                continue;
            }
            try {
                $previous = $storage->getEvaluatePermissions();
                $storage->setEvaluatePermissions(false);
                GeneralUtility::makeInstance(Indexer::class, $storage)->processChangesInStorages();
                $storage->setEvaluatePermissions($previous);
                $n++;
            } catch (\Throwable) {
                // Index ist ein Zusatz
            }
        }
        return $n;
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        $s = $this->client->settings();
        $split = static fn($v): array => array_values(array_filter(array_map('trim', explode(',', (string)$v))));
        return [
            'mode' => ($s['mode'] ?? 'replace') === 'check' ? 'check' : 'replace',
            'storages' => array_map('intval', $split($s['storages'] ?? '')),
            'folders' => $split($s['folders'] ?? ''),
            'exclude' => $split($s['excludeFolders'] ?? '/_dokaudit_backup/,/_processed_/,/_temp_/'),
            'minGain' => max(0, (int)($s['minGain'] ?? 1)),
            'autoQueue' => (bool)($s['autoQueue'] ?? true),
            'backupFolder' => '/' . trim((string)($s['backupFolder'] ?? '/_dokaudit_backup/'), '/') . '/',
            'maxBytes' => max(1, (int)($s['maxSizeMb'] ?? 50)) * 1024 * 1024,
        ];
    }

    public function isEligible(File $file): bool
    {
        $s = $this->settings();
        if (strtolower($file->getExtension()) !== 'pdf' && $file->getMimeType() !== 'application/pdf') {
            return false;
        }
        $storage = $file->getStorage();
        if ($s['storages'] !== [] && !in_array($storage->getUid(), $s['storages'], true)) {
            return false;
        }
        if ($s['storages'] === [] && $storage->getDriverType() !== 'Local') {
            return false;
        }
        return $this->pathAllowed($file->getIdentifier(), $s);
    }

    /** @param array<string, mixed> $s */
    private function pathAllowed(string $identifier, array $s): bool
    {
        foreach (array_merge($s['exclude'], [$s['backupFolder']]) as $ex) {
            if ($ex !== '' && str_contains($identifier, '/' . trim($ex, '/') . '/')) {
                return false;
            }
        }
        if ($s['folders'] === []) {
            return true;
        }
        foreach ($s['folders'] as $f) {
            if (str_starts_with($identifier, '/' . ltrim(rtrim($f, '/') . '/', '/'))) {
                return true;
            }
        }
        return false;
    }

    /** Neue und seit dem letzten Lauf geänderte PDFs eintragen. Liefert die Anzahl. */
    public function discover(int $limit = 500): int
    {
        $s = $this->settings();
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $qb->getRestrictions()->removeAll();
        $qb->select('f.uid', 'f.storage', 'f.identifier', 'f.sha1', 'f.size', 's.status', 's.sha1_original', 's.sha1_fixed')
            ->from('sys_file', 'f')
            ->leftJoin('f', FileStateRepository::TABLE, 's', $qb->expr()->eq('s.file', $qb->quoteIdentifier('f.uid')))
            ->where(
                $qb->expr()->eq('f.missing', 0),
                $qb->expr()->or(
                    $qb->expr()->eq('f.mime_type', $qb->createNamedParameter('application/pdf')),
                    $qb->expr()->eq('f.extension', $qb->createNamedParameter('pdf')),
                ),
            )
            ->orderBy('f.uid', 'ASC');
        if ($s['storages'] !== []) {
            $qb->andWhere($qb->expr()->in('f.storage', $qb->createNamedParameter($s['storages'], Connection::PARAM_INT_ARRAY)));
        }
        $n = 0;
        foreach ($qb->executeQuery()->iterateAssociative() as $row) {
            if ($n >= $limit) {
                break;
            }
            if (!$this->pathAllowed((string)$row['identifier'], $s)) {
                continue;
            }
            $sha = (string)$row['sha1'];
            $known = $row['status'] !== null;
            // geändert = weder das gemeldete Original noch unsere korrigierte Fassung
            $changed = $known && $sha !== '' && $sha !== $row['sha1_original'] && $sha !== $row['sha1_fixed'];
            if ($known && !$changed) {
                continue;
            }
            if ($s['storages'] === []) {
                try {
                    $storage = $this->resourceFactory->getStorageObject((int)$row['storage']);
                    if ($storage->getDriverType() !== 'Local') {
                        continue;
                    }
                } catch (\Throwable) {
                    continue;
                }
            }
            if ((int)$row['size'] > $s['maxBytes']) {
                $this->states->upsert((int)$row['uid'], ['storage' => (int)$row['storage'], 'identifier' => $row['identifier'],
                    'status' => 'skipped', 'message' => 'Datei größer als erlaubt', 'sha1_original' => $sha]);
                continue;
            }
            $this->states->markNew((int)$row['uid'], (int)$row['storage'], (string)$row['identifier'], $sha);
            $n++;
        }
        return $n;
    }

    /** „new“ an DokAudit übergeben. */
    public function submit(int $limit = 20, ?callable $log = null): int
    {
        $n = 0;
        foreach ($this->states->findByStatus(['new'], $limit) as $row) {
            $uid = (int)$row['file'];
            try {
                $file = $this->resourceFactory->getFileObject($uid);
                if ($file->isMissing()) {
                    $this->states->delete($uid);
                    continue;
                }
                $local = $file->getForLocalProcessing(false);
                $doc = $this->client->upload($local, $file->getName());
                $this->states->upsert($uid, ['status' => 'submitted', 'document_id' => (string)($doc['id'] ?? ''),
                    'message' => '', 'sha1_original' => $file->getSha1(), 'submitted_at' => time()]);
                $log && $log(sprintf('übermittelt: %s', $file->getIdentifier()));
                $n++;
            } catch (DokAuditException $e) {
                if ($e->status === 429) {
                    $log && $log('Rate-Limit erreicht — Rest beim nächsten Lauf.');
                    break;
                }
                $this->states->upsert($uid, ['status' => 'error', 'message' => mb_substr($e->getMessage(), 0, 1000)]);
                $log && $log(sprintf('Fehler bei Datei %d: %s', $uid, $e->getMessage()));
                if (in_array($e->status, [0, 401, 403], true)) {
                    break;   // Verbindung/Schlüssel kaputt — nicht alle Dateien als Fehler markieren
                }
            } catch (\Throwable $e) {
                $this->states->upsert($uid, ['status' => 'error', 'message' => mb_substr($e->getMessage(), 0, 1000)]);
            }
        }
        return $n;
    }

    /** Laufende Aufträge abfragen und Ergebnisse übernehmen. */
    public function collect(int $limit = 50, ?callable $log = null): int
    {
        $n = 0;
        foreach ($this->states->findByStatus(['submitted', 'waiting'], $limit) as $row) {
            $uid = (int)$row['file'];
            try {
                $doc = $this->client->document((string)$row['document_id']);
            } catch (DokAuditException $e) {
                if ($e->status === 404) {
                    $this->states->upsert($uid, ['status' => 'new', 'message' => 'Dokument bei DokAudit nicht mehr vorhanden — wird neu übermittelt']);
                }
                continue;
            }
            $status = (string)($doc['status'] ?? '');
            if (in_array($status, ['uploaded', 'queued', 'processing'], true)) {
                continue;
            }
            if ($status === 'waiting_credits') {
                $this->states->upsert($uid, ['status' => 'waiting', 'message' => 'Wartet auf DokAudit-Guthaben']);
                continue;
            }
            if ($status === 'error') {
                $this->states->upsert($uid, ['status' => 'error', 'message' => mb_substr((string)($doc['error'] ?? 'Verarbeitung fehlgeschlagen'), 0, 1000)]);
                continue;
            }
            try {
                $this->apply($row, $doc, $log);
                $n++;
            } catch (\Throwable $e) {
                $this->states->upsert($uid, ['status' => 'error', 'message' => mb_substr($e->getMessage(), 0, 1000)]);
                $log && $log(sprintf('Fehler beim Übernehmen von Datei %d: %s', $uid, $e->getMessage()));
            }
        }
        return $n;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $doc
     */
    private function apply(array $row, array $doc, ?callable $log): void
    {
        $s = $this->settings();
        $uid = (int)$row['file'];
        $file = $this->resourceFactory->getFileObject($uid);
        $before = isset($doc['score_before']) ? (int)$doc['score_before'] : null;
        $after = isset($doc['score_after']) ? (int)$doc['score_after'] : null;
        $base = ['score_before' => $before, 'score_after' => $after, 'pages' => $doc['pages'] ?? null, 'processed_at' => time(),
            'seals' => implode(',', array_keys(array_filter((array)($doc['seals'] ?? [])))),
            'hub_url' => mb_substr((string)($doc['links']['hub'] ?? ''), 0, 255)];

        if ($file->getSha1() !== $row['sha1_original']) {
            // Während der Verarbeitung neu hochgeladen — Ergebnis verwerfen, neu einreihen
            $this->states->markNew($uid, $file->getStorage()->getUid(), $file->getIdentifier(), $file->getSha1());
            return;
        }
        $gain = ($after ?? 0) - ($before ?? 0);
        if ($s['mode'] !== 'replace' || empty($doc['has_fixed']) || $after === null || $gain < $s['minGain']) {
            $why = $s['mode'] !== 'replace' ? 'Modus „nur prüfen“' : ($gain < $s['minGain'] ? 'keine ausreichende Verbesserung' : 'keine korrigierte Fassung');
            $this->states->upsert($uid, $base + ['status' => 'checked', 'message' => $why]);
            $log && $log(sprintf('geprüft (%s): %s %s', $why, $file->getIdentifier(), $this->scores($before, $after)));
            return;
        }

        $tmp = GeneralUtility::tempnam('dokaudit_', '.pdf');
        try {
            $this->client->download((string)$row['document_id'], 'fixed', $tmp);
            $backup = $this->backup($file, $s['backupFolder']);
            self::$replacing = true;
            try {
                $file->getStorage()->replaceFile($file, $tmp);
            } finally {
                self::$replacing = false;
            }
            $file = $this->resourceFactory->getFileObject($uid);   // frische sha1/size
            $this->states->upsert($uid, $base + ['status' => 'replaced', 'message' => '',
                'sha1_fixed' => $file->getSha1(), 'backup_identifier' => $backup]);
            $log && $log(sprintf('ersetzt: %s %s', $file->getIdentifier(), $this->scores($before, $after)));
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private function scores(?int $before, ?int $after): string
    {
        return sprintf('(%s → %s Punkte)', $before ?? '–', $after ?? '–');
    }

    /** Original in den Sicherungsordner kopieren. Liefert den Identifier der Sicherung. */
    private function backup(File $file, string $backupFolder): string
    {
        $storage = $file->getStorage();
        $folder = $this->ensureFolder($storage, $backupFolder);
        $copy = $file->getForLocalProcessing(true);
        $name = sprintf('%d_%s_%s', $file->getUid(), substr($file->getSha1(), 0, 8), $file->getName());
        self::$replacing = true;
        try {
            $added = $storage->addFile($copy, $folder, $name, 'rename', true);
        } finally {
            self::$replacing = false;
        }
        return $added->getIdentifier();
    }

    private function ensureFolder(ResourceStorage $storage, string $path): Folder
    {
        $path = '/' . trim($path, '/') . '/';
        if ($storage->hasFolder($path)) {
            return $storage->getFolder($path);
        }
        $parent = $storage->getRootLevelFolder(false);
        foreach (array_filter(explode('/', trim($path, '/'))) as $part) {
            $parent = $parent->hasFolder($part) ? $parent->getSubfolder($part) : $parent->createFolder($part);
        }
        return $parent;
    }

    /** Original aus dem Sicherungsordner zurückspielen. */
    public function restore(int $fileUid): void
    {
        $row = $this->states->findByFile($fileUid);
        if ($row === null || $row['status'] !== 'replaced' || (string)$row['backup_identifier'] === '') {
            throw new \RuntimeException('Für diese Datei gibt es keine Sicherung.');
        }
        $file = $this->resourceFactory->getFileObject($fileUid);
        $storage = $file->getStorage();
        $backup = $storage->getFile((string)$row['backup_identifier']);
        if (!$backup instanceof File) {
            throw new \RuntimeException('Sicherung nicht gefunden: ' . $row['backup_identifier']);
        }
        $copy = $backup->getForLocalProcessing(true);
        self::$replacing = true;
        try {
            $storage->replaceFile($file, $copy);
        } finally {
            self::$replacing = false;
            if (is_file($copy)) {
                @unlink($copy);
            }
        }
        $file = $this->resourceFactory->getFileObject($fileUid);
        $this->states->upsert($fileUid, ['status' => 'restored', 'message' => 'Original wiederhergestellt',
            'sha1_original' => $file->getSha1(), 'sha1_fixed' => '']);
    }

    /** Siegel und Hub-Link für ältere Einträge nachladen (vor Version 1.1 nicht gespeichert). */
    public function refreshMeta(int $limit = 10): int
    {
        $n = 0;
        foreach ($this->states->findRecent(300) as $r) {
            if ($n >= $limit) {
                break;
            }
            if ((string)$r['document_id'] === '' || (string)($r['hub_url'] ?? '') !== ''
                || !in_array($r['status'], ['replaced', 'checked'], true)) {
                continue;
            }
            try {
                $doc = $this->client->document((string)$r['document_id']);
            } catch (DokAuditException $e) {
                if (in_array($e->status, [403, 404], true)) {
                    // gehört zu einer anderen Organisation (Schlüssel gewechselt) – nicht jedes Mal neu fragen
                    $this->states->upsert((int)$r['file'], ['hub_url' => '-']);
                }
                continue;
            }
            $this->states->upsert((int)$r['file'], [
                'seals' => implode(',', array_keys(array_filter((array)($doc['seals'] ?? [])))),
                'hub_url' => mb_substr((string)($doc['links']['hub'] ?? ''), 0, 255),
            ]);
            $n++;
        }
        return $n;
    }

    /** Datei erneut einreihen (z. B. nach Fehler oder Wiederherstellung). */
    public function requeue(int $fileUid): void
    {
        $file = $this->resourceFactory->getFileObject($fileUid);
        $this->states->markNew($fileUid, $file->getStorage()->getUid(), $file->getIdentifier(), $file->getSha1());
    }

    /**
     * Ein kompletter Abgleich.
     * @return array{discovered:int, submitted:int, collected:int}
     */
    public function sync(int $limit = 20, ?callable $log = null, bool $index = true): array
    {
        if ($index) {
            $this->indexStorages();
        }
        $d = $this->discover();
        $c1 = $this->collect(200, $log);
        $sub = $this->submit($limit, $log);
        return ['discovered' => $d, 'submitted' => $sub, 'collected' => $c1];
    }
}

<?php

declare(strict_types=1);

namespace DokAudit\Typo3\EventListener;

use DokAudit\Typo3\Domain\Repository\FileStateRepository;
use DokAudit\Typo3\Service\PdfOptimizer;
use TYPO3\CMS\Core\Resource\Event\AfterFileAddedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileReplacedEvent;
use TYPO3\CMS\Core\Resource\File;

/** Neue oder ersetzte PDFs automatisch für den nächsten Abgleich einreihen. */
final class QueueUploadedPdf
{
    public function __construct(private readonly PdfOptimizer $optimizer, private readonly FileStateRepository $states) {}

    public function __invoke(AfterFileAddedEvent|AfterFileReplacedEvent $event): void
    {
        if (PdfOptimizer::$replacing) {
            return;
        }
        $file = $event->getFile();
        if (!$file instanceof File) {
            return;
        }
        try {
            if (!$this->optimizer->settings()['autoQueue'] || !$this->optimizer->isEligible($file)) {
                return;
            }
            $this->states->markNew($file->getUid(), $file->getStorage()->getUid(), $file->getIdentifier(), $file->getSha1());
        } catch (\Throwable) {
            // Einreihen ist ein Zusatz — Upload nie blockieren
        }
    }
}

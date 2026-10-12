<?php

declare(strict_types=1);

namespace DokAudit\Typo3\Controller;

use DokAudit\Typo3\Api\DokAuditClient;
use DokAudit\Typo3\Domain\Repository\FileStateRepository;
use DokAudit\Typo3\Service\PdfOptimizer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/** Backend-Modul „Datei → DokAudit“: Übersicht, Abgleich starten, Original wiederherstellen. */
final class ModuleController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly PdfOptimizer $optimizer,
        private readonly FileStateRepository $states,
        private readonly DokAuditClient $client,
        private readonly UriBuilder $uriBuilder,
        private readonly FlashMessageService $flashMessageService,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $action = (string)($request->getQueryParams()['action'] ?? 'index');
        if ($request->getMethod() === 'POST') {
            $body = (array)$request->getParsedBody();
            try {
                match ($action) {
                    'sync' => $this->flash($this->syncMessage(), ContextualFeedbackSeverity::OK),
                    'restore' => $this->restore((int)($body['file'] ?? 0)),
                    'requeue' => $this->requeue((int)($body['file'] ?? 0)),
                    'settings' => $this->saveSettings($body),
                    default => null,
                };
            } catch (\Throwable $e) {
                $this->flash($e->getMessage(), ContextualFeedbackSeverity::ERROR);
            }
            return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute('file_dokaudit'));
        }
        return $this->index($request);
    }

    private function index(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle('DokAudit');
        $account = null;
        $accountError = '';
        if ($this->client->isConfigured()) {
            // fertige Ergebnisse gleich beim Öffnen abholen (nur Statusabfragen, keine Uploads)
            $pending = $this->states->stats();
            if ((($pending['submitted'] ?? 0) + ($pending['waiting'] ?? 0)) > 0) {
                try {
                    $this->optimizer->collect(20);
                } catch (\Throwable) {
                    // Anzeige geht vor – der nächste Abgleich holt den Rest
                }
            }
            try {
                $this->optimizer->refreshMeta(10);
            } catch (\Throwable) {
            }
            try {
                $account = $this->client->account();
            } catch (\Throwable $e) {
                $accountError = $e->getMessage();
            }
        }
        $rows = array_map(function (array $r): array {
            $r['gain'] = ($r['score_after'] !== null && $r['score_before'] !== null) ? (int)$r['score_after'] - (int)$r['score_before'] : null;
            $r['name'] = basename((string)$r['identifier']);
            $r['folder'] = dirname((string)$r['identifier']);
            $labels = ['pdf_ua1' => ['PDF/UA-1', '#047857'], 'wcag22' => ['WCAG 2.2', '#1d4ed8'],
                'pdfa2a' => ['PDF/A-2a', '#b45309'], 'pdf_ua2' => ['PDF/UA-2', '#6d28d9']];
            $r['siegel'] = [];
            foreach (array_filter(explode(',', (string)($r['seals'] ?? ''))) as $k) {
                if (isset($labels[$k])) {
                    $r['siegel'][] = ['label' => $labels[$k][0], 'farbe' => $labels[$k][1]];
                }
            }
            $r['hub'] = str_starts_with((string)($r['hub_url'] ?? ''), 'http') ? $r['hub_url'] : '';
            $r['voll'] = (int)($r['score_after'] ?? 0) === 100;
            $r['vorher'] = $r['score_before'] === null ? '–' : (string)$r['score_before'];
            $r['nachher'] = $r['score_after'] === null ? '–' : (string)$r['score_after'];
            return $r;
        }, $this->states->findRecent(300));
        $view->assignMultiple([
            'configured' => $this->client->isConfigured(),
            'settings' => $this->optimizer->settings(),
            'apiUrl' => $this->client->settings()['apiUrl'],
            'hubUrl' => $this->client->settings()['apiUrl'] . '/hub',
            'cfg' => $this->formValues(),
            'offen' => max(0, $this->pdfCount() - (int)($this->states->stats()['total'] ?? 0)),
            'guthabenKnapp' => is_array($account) && (int)($account['credits_available'] ?? 0) < 20,
            'seitenRest' => is_array($account) ? (int)($account['credits_available'] ?? 0) * 5 : 0,
            'update' => $this->updateInfo($account),
            'version' => DokAuditClient::pluginVersion(),
            'account' => $account,
            'accountError' => $accountError,
            'stats' => $this->states->stats(),
            'rows' => $rows,
            'actions' => [
                'sync' => (string)$this->uriBuilder->buildUriFromRoute('file_dokaudit', ['action' => 'sync']),
                'restore' => (string)$this->uriBuilder->buildUriFromRoute('file_dokaudit', ['action' => 'restore']),
                'requeue' => (string)$this->uriBuilder->buildUriFromRoute('file_dokaudit', ['action' => 'requeue']),
                'settings' => (string)$this->uriBuilder->buildUriFromRoute('file_dokaudit', ['action' => 'settings']),
            ],
        ]);
        return $view->renderResponse('Module/Index');
    }

    /** PDFs in der Dateiliste (alle Speicher, ohne fehlende Dateien). */
    private function pdfCount(): int
    {
        try {
            $qb = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class)
                ->getQueryBuilderForTable('sys_file');
            $qb->getRestrictions()->removeAll();
            return (int)$qb->count('uid')->from('sys_file')->where(
                $qb->expr()->eq('missing', 0),
                $qb->expr()->or(
                    $qb->expr()->eq('mime_type', $qb->createNamedParameter('application/pdf')),
                    $qb->expr()->eq('extension', $qb->createNamedParameter('pdf')),
                ),
                $qb->expr()->notLike('identifier', $qb->createNamedParameter('%/_dokaudit_backup/%')),
            )->executeQuery()->fetchOne();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return array<string, mixed> */
    private function formValues(): array
    {
        try {
            $cfg = (array)$this->extensionConfiguration->get('dokaudit');
        } catch (\Throwable) {
            $cfg = [];
        }
        $stored = trim((string)($cfg['apiKey'] ?? ''));
        $env = (string)(getenv('DOKAUDIT_API_KEY') ?: '');
        $shown = $stored !== '' ? $stored : $env;
        return [
            'apiUrl' => (string)($cfg['apiUrl'] ?? 'https://dokaudit.de') ?: 'https://dokaudit.de',
            'mode' => ($cfg['mode'] ?? 'replace') === 'check' ? 'check' : 'replace',
            'isReplace' => ($cfg['mode'] ?? 'replace') !== 'check',
            'autoQueue' => (bool)($cfg['autoQueue'] ?? true),
            'keyMasked' => $shown !== '' ? substr($shown, 0, 13) . '…' . substr($shown, -4) : '',
            'keySource' => $stored !== '' ? 'config' : ($env !== '' ? 'env' : ''),
        ];
    }

    /**
     * Update-Hinweis: DokAudit meldet in /account die aktuelle Plugin-Version.
     * @param array<string, mixed>|null $account
     * @return array<string, string>|null
     */
    private function updateInfo(?array $account): ?array
    {
        $latest = (array)($account['plugins']['typo3'] ?? []);
        $installed = DokAuditClient::pluginVersion();
        if (empty($latest['version']) || $installed === '' || !version_compare((string)$latest['version'], $installed, '>')) {
            return null;
        }
        return ['installed' => $installed, 'latest' => (string)$latest['version'],
            'download' => (string)($latest['download_url'] ?? ''), 'composer' => (string)($latest['composer'] ?? 'dokaudit/typo3-dokaudit'),
            'changelog' => (string)($latest['changelog_url'] ?? '')];
    }

    private function syncMessage(): string
    {
        $r = $this->optimizer->sync(20);
        return sprintf(
            'Abgleich: %d neu gefunden, %d übermittelt, %d Ergebnisse übernommen. Laufende Prüfungen werden beim nächsten Abgleich abgeholt.',
            $r['discovered'],
            $r['submitted'],
            $r['collected']
        );
    }

    /**
     * Einstellungen aus dem Modul speichern (gleiche Werte wie die Erweiterungskonfiguration).
     * Ein neuer Schlüssel wird vorher bei DokAudit geprüft – gespeichert wird nur ein gültiger.
     *
     * @param array<string, mixed> $body
     */
    private function saveSettings(array $body): void
    {
        try {
            $cfg = (array)$this->extensionConfiguration->get('dokaudit');
        } catch (\Throwable) {
            $cfg = [];
        }
        $apiUrl = rtrim(trim((string)($body['apiUrl'] ?? '')), '/') ?: 'https://dokaudit.de';
        if (!preg_match('#^https?://#', $apiUrl)) {
            throw new \RuntimeException('Die API-Adresse muss mit https:// beginnen.');
        }
        $key = trim((string)($body['apiKey'] ?? ''));
        $account = null;
        if ($key !== '') {
            if (!str_starts_with($key, 'dka_')) {
                throw new \RuntimeException('Ein DokAudit-API-Schlüssel beginnt mit „dka_“.');
            }
            try {
                $account = $this->client->account($key, $apiUrl);
            } catch (\Throwable $e) {
                throw new \RuntimeException('DokAudit akzeptiert diesen Schlüssel nicht: ' . $e->getMessage());
            }
            $cfg['apiKey'] = $key;
        } elseif (!empty($body['removeKey'])) {
            $cfg['apiKey'] = '';
        }
        $cfg['apiUrl'] = $apiUrl;
        $cfg['mode'] = ($body['mode'] ?? 'replace') === 'check' ? 'check' : 'replace';
        $cfg['autoQueue'] = !empty($body['autoQueue']) ? '1' : '0';
        $this->extensionConfiguration->set('dokaudit', $cfg);
        $this->flash($account !== null
            ? sprintf(
                'Gespeichert. Der Schlüssel gehört zur Organisation „%s“ (verfügbares Guthaben: %d Credits). Dokumente erscheinen dort im Hub und werden mit deren Guthaben abgerechnet.',
                (string)($account['organization']['name'] ?? '?'),
                (int)($account['credits_available'] ?? 0)
            )
            : 'Einstellungen gespeichert.', ContextualFeedbackSeverity::OK);
    }

    private function restore(int $file): void
    {
        $this->optimizer->restore($file);
        $this->flash('Original wiederhergestellt.', ContextualFeedbackSeverity::OK);
    }

    private function requeue(int $file): void
    {
        $this->optimizer->requeue($file);
        $this->flash('Datei wird beim nächsten Abgleich erneut geprüft.', ContextualFeedbackSeverity::INFO);
    }

    private function flash(string $text, ContextualFeedbackSeverity $severity): void
    {
        $msg = new FlashMessage($text, 'DokAudit', $severity, true);
        $this->flashMessageService->getMessageQueueByIdentifier()->addMessage($msg);
    }
}

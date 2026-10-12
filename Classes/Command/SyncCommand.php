<?php

declare(strict_types=1);

namespace DokAudit\Typo3\Command;

use DokAudit\Typo3\Api\DokAuditClient;
use DokAudit\Typo3\Domain\Repository\FileStateRepository;
use DokAudit\Typo3\Service\PdfOptimizer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Bootstrap;

/**
 * vendor/bin/typo3 dokaudit:sync [--limit=20] [--wait=0]
 * Für den Scheduler geeignet („Konsolenbefehl ausführen“), z. B. alle 10 Minuten.
 */
final class SyncCommand extends Command
{
    public function __construct(
        private readonly PdfOptimizer $optimizer,
        private readonly DokAuditClient $client,
        private readonly FileStateRepository $states,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('PDFs der Dateiliste an DokAudit übergeben und korrigierte Fassungen übernehmen')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Höchstens so viele neue Dateien je Lauf übermitteln', '20')
            ->addOption('wait', 'w', InputOption::VALUE_REQUIRED, 'Bis zu so vielen Sekunden auf Ergebnisse warten (0 = nicht warten)', '0')
            ->addOption('no-index', null, InputOption::VALUE_NONE, 'Dateiindex vorher nicht aktualisieren');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Bootstrap::initializeBackendAuthentication();
        $io = new SymfonyStyle($input, $output);
        if (!$this->client->isConfigured()) {
            $io->error('Kein API-Schlüssel konfiguriert (Erweiterungseinstellungen → dokaudit oder DOKAUDIT_API_KEY).');
            return Command::FAILURE;
        }
        $log = static fn(string $m) => $io->writeln('  ' . $m);
        $r = $this->optimizer->sync(max(1, (int)$input->getOption('limit')), $log, !$input->getOption('no-index'));
        $deadline = time() + max(0, (int)$input->getOption('wait'));
        while (time() < $deadline) {
            sleep(5);
            $r['collected'] += $this->optimizer->collect(200, $log);
            if ($this->states->findByStatus(['submitted'], 1) === []) {
                break;
            }
        }
        $io->success(sprintf('%d neu gefunden, %d übermittelt, %d Ergebnisse übernommen.', $r['discovered'], $r['submitted'], $r['collected']));
        return Command::SUCCESS;
    }
}

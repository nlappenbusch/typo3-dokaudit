<?php

declare(strict_types=1);

namespace DokAudit\Typo3\Command;

use DokAudit\Typo3\Service\PdfOptimizer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Core\Bootstrap;

/** vendor/bin/typo3 dokaudit:restore <sys_file-UID> — Original zurückspielen. */
final class RestoreCommand extends Command
{
    public function __construct(private readonly PdfOptimizer $optimizer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Original einer von DokAudit ersetzten Datei wiederherstellen')
            ->addArgument('file', InputArgument::REQUIRED, 'UID der Datei (sys_file)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Bootstrap::initializeBackendAuthentication();
        try {
            $this->optimizer->restore((int)$input->getArgument('file'));
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $output->writeln('<info>Original wiederhergestellt.</info>');
        return Command::SUCCESS;
    }
}

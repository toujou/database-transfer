<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Toujou\DatabaseTransfer\Database\FastImportConnection;
use Toujou\DatabaseTransfer\Export\SelectionFactory;
use Toujou\DatabaseTransfer\Service\TransferService;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[AsCommand(
    name: 'database:transfer',
    description: 'Transfers data from one database to another',
)]
class TransferCommand extends Command
{
    public function __construct(
        private SelectionFactory $selectionFactory,
        private TransferService $transferService,
        private ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addArgument(
                'dsn',
                InputArgument::REQUIRED,
                'The source database connection string (DSN) from which data will be imported. e.g. "mysqli://db:db@db:3306/main"',
            )
            ->addArgument(
                'import-source-name',
                InputArgument::REQUIRED,
                'Identifier of the import source. This name is used across all imports to identify and group data from the same source system. '
                . 'It also maintains the mapping between source and target records and influences update behavior. e.g. "main"',
            )
            ->addOption(
                'all',
                null,
                InputOption::VALUE_NONE,
                'Import all pages',
            )
            ->addOption(
                'pid',
                null,
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Root pages of the source page tree to import. Format: "{pid}:{level}"',
            )
            ->addOption(
                'include-table',
                null,
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Include all records of this table. Examples: "ALL", "tt_content", "sys_file_reference", etc.',
                [SelectionFactory::TABLES_ALL],
            )
            ->addOption(
                'exclude-table',
                null,
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Exclude all records of this table. Examples: "tt_content", "sys_file_reference", etc.',
                [
                    'tx_migrations_domain_model_migrationstatus',
                    'tx_yoastseo_prominent_word',
                    'tx_sentmail_mail',
                ],
            )
            ->addOption(
                'include-record',
                null,
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Include this specific record. Pattern is "{table}:{record}". Examples: "tt_content:12", etc.',
            )
            ->addOption(
                'exclude-record',
                null,
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Exclude this specific record. Pattern is "{table}:{record}". Examples: "fe_users:3", etc.',
            )
            ->addOption(
                'include-related',
                null,
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Include record relations to this table, including the related record. Examples: "ALL", "sys_category", etc.',
                [SelectionFactory::TABLES_ALL],
            )
            ->addOption(
                'include-static',
                null,
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Include record relations to this table, excluding the related record. Examples: "ALL", "be_users", etc.',
            )
            ->addOption(
                'delta-update',
                null,
                InputOption::VALUE_NONE,
                'Only update records that have changed by comparing their timestamps (if available).',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Only update records that have changed by comparing their timestamps (if available).',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Bootstrap::initializeBackendAuthentication();

        $startTime = microtime(true);

        $symfonyStyle = new SymfonyStyle($input, $output);

        $options = $input->getOptions();

        $all = $input->getOption('all');
        $pid  = $input->getOption('pid');
        $importSourceName = $input->getArgument('import-source-name');

        if (!$all && empty($pid)) {
            $symfonyStyle->error('You must use --all, or --pid');
            return Command::FAILURE;
        }

        $sourceConnectionName = uniqid('', false);
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections'][$sourceConnectionName] = [
            'url' => $input->getArgument('dsn'),
            'driver' => '',
            'wrapperClass' => FastImportConnection::class,
        ];
        $selection = $this->selectionFactory->buildFromCommandOptions(
            $this->connectionPool->getConnectionByName($sourceConnectionName),
            $options,
        );

        $this->transferService->transfer(
            $selection,
            $sourceConnectionName,
            $importSourceName,
            (bool)$input->getOption('delta-update'),
            (bool)$input->getOption('dry-run'),
            $symfonyStyle,
        );

        if ($symfonyStyle->isVerbose()) {
            $memory = memory_get_usage(true) / 1024 / 1024;
            $peak = memory_get_peak_usage(true) / 1024 / 1024;
            $elapsed = microtime(true) - $startTime;

            $symfonyStyle->writeln(sprintf(
                '<info>Profiling</info> | Current: %.2f MB | Peak: %.2f MB | %.3fs (total)',
                $memory,
                $peak,
                $elapsed,
            ));
        }

        return Command::SUCCESS;
    }
}

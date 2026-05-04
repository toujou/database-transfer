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

#[AsCommand(
    name: 'database:transfer',
    description: 'Transfers data from one database to another',
)]
class TransferCommand extends Command
{
    public function __construct(
        private SelectionFactory $selectionFactory,
        private TransferService $transferService,
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
                'The target database connection string',
            )
            ->addOption(
                'all',
                null,
                InputOption::VALUE_NONE,
                'Export all pages',
            )
            ->addOption(
                'site',
                null,
                InputOption::VALUE_OPTIONAL,
                'The identifier of the exported site.',
            )
            ->addOption(
                'pid',
                null,
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'The root pages of the exported page tree. Pattern is "{pid}:{level}"',
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
                'import-source',
                null,
                InputOption::VALUE_OPTIONAL,
                'Identifier of the data source (e.g. "default", "main" ) used to distinguish imports and determine update behavior',
                'default',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Bootstrap::initializeBackendAuthentication();

        $symfonyStyle = new SymfonyStyle($input, $output);

        $options = $input->getOptions();

        $all = $input->getOption('all');
        $site = $input->getOption('site');
        $pid  = $input->getOption('pid');
        $importSource = $input->getOption('import-source');

        if ($all && ($site || $pid)) {
            $symfonyStyle->error('You cannot combine --all with --site or --pid');
            return Command::FAILURE;
        }

        if (!$all && !$site && empty($pid)) {
            $symfonyStyle->error('You must use --all, --site, or --pid');
            return Command::FAILURE;
        }

        $selection = $this->selectionFactory->buildFromCommandOptions($options);

        $connectionName = uniqid('', false);
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections'][$connectionName] = [
            'url' => $input->getArgument('dsn'),
            'driver' => '',
            'wrapperClass' => FastImportConnection::class,
        ];

        $this->transferService->transfer($selection, $connectionName, $importSource);

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Tests\Functional\Export;

use PHPUnit\Framework\Attributes\Test;
use Toujou\DatabaseTransfer\Database\DatabaseContext;
use Toujou\DatabaseTransfer\Export\SelectionFactory;
use Toujou\DatabaseTransfer\Service\TransferService;
use Toujou\DatabaseTransfer\Tests\Functional\AbstractTransferTestCase;
use TYPO3\CMS\Core\Database\ReferenceIndex;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class IrreRecordsTest extends AbstractTransferTestCase
{
    protected function setUp(): void
    {

        $this->testExtensionsToLoad = [
            ...$this->testExtensionsToLoad,
            'typo3conf/ext/toujou_database_transfer/Tests/Functional/Fixtures/Extensions/test_irre_csv',
            'typo3conf/ext/toujou_database_transfer/Tests/Functional/Fixtures/Extensions/test_irre_mm',
            'typo3conf/ext/toujou_database_transfer/Tests/Functional/Fixtures/Extensions/test_irre_mnsymmetric',
            'typo3conf/ext/toujou_database_transfer/Tests/Functional/Fixtures/Extensions/test_irre_foreignfield',
            'typo3conf/ext/toujou_database_transfer/Tests/Functional/Fixtures/Extensions/test_irre_mnattributeinline',
            'typo3conf/ext/toujou_database_transfer/Tests/Functional/Fixtures/Extensions/test_irre_mnattributesimple',
        ];

        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DatabaseImports/irre_records.csv');

        GeneralUtility::makeInstance(ReferenceIndex::class)->updateIndex(false);

    }

    #[Test]
    public function importIrreRecords(): void
    {
        $targetConnectionName = $this->createSqliteConnection('export');

        $options = [
            'pid' => [1],
            'include-table' => ['pages', 'tt_content', 'tx_testirrecsv_hotel'],
        ];
        $selectionFactory = $this->get(SelectionFactory::class);
        $selection = $selectionFactory->buildFromCommandOptions($options);

        $transferService = $this->get(TransferService::class);
        $transferService->transfer($selection, $targetConnectionName, 'default');

        $targetConnection = $this->getConnectionPool()->getConnectionByName($targetConnectionName);
        $this->renameImportIndexToWellKnownTableName($targetConnection);

        $databaseContext = new DatabaseContext(
            $targetConnection,
            $targetConnectionName,
            [
                'pages',
                'tt_content',
                'sys_databasetransfer_import',
            ],
        );

        // tt_content:2 header_link field contains a reference to file:40 which is on the fallback storage and thus not part
        // of the reference index. As header_link is a link field, this reference is NOT cleared during export.
        $databaseContext->runWithinConnection(function () {
            $this->assertCSVDataSet(__DIR__ . '/../Fixtures/DatabaseExports/irre_records.csv');
        });
    }
}

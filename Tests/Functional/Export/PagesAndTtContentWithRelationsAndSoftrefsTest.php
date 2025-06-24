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

class PagesAndTtContentWithRelationsAndSoftrefsTest extends AbstractTransferTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        # $this->importCSVDataSet(__DIR__ . '/../Fixtures/DatabaseImports/pages.csv');
        # $this->importCSVDataSet(__DIR__ . '/../Fixtures/DatabaseImports/tt_content-with-image.csv');
        # $this->importCSVDataSet(__DIR__ . '/../Fixtures/DatabaseImports/sys_file_metadata.csv');
        # $this->importCSVDataSet(__DIR__ . '/../Fixtures/DatabaseImports/sys_file_reference.csv');
        # $this->importCSVDataSet(__DIR__ . '/../Fixtures/DatabaseImports/sys_file_storage.csv');
    }

    #[Test]
    public function exportPagesAndRelatedTtContentWithFlexFormRelation(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DatabaseImports/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DatabaseImports/tt_content-with-flexform-relation.csv');

        $GLOBALS['TCA']['tt_content']['columns']['pi_flexform']['config']['ds']['default'] = '
<T3DataStructure>
    <ROOT>
        <type>array</type>
        <el>
            <flexFormRelation>
                <label>FlexForm relation</label>
                <config>
                    <type>group</type>
                    <allowed>pages</allowed>
                    <size>1</size>
                    <maxitems>1</maxitems>
                    <minitems>0</minitems>
                </config>
            </flexFormRelation>
        </el>
    </ROOT>
</T3DataStructure>';

        GeneralUtility::makeInstance(ReferenceIndex::class)->updateIndex(false);

        $targetConnectionName = $this->createSqliteConnection('export');

        $options = [
            'pid' => [1],
            'include-table' => ['pages', 'tt_content'],
        ];
        $selectionFactory = $this->get(SelectionFactory::class);
        $selection = $selectionFactory->buildFromCommandOptions($options);

        $transferService = $this->get(TransferService::class);
        $transferService->transfer($selection, $targetConnectionName);

        $targetConnection = $this->getConnectionPool()->getConnectionByName($targetConnectionName);
        $this->renameImportIndexToWellKnownTableName($targetConnection, $targetConnectionName);

        $databaseContext = new DatabaseContext(
            $targetConnection,
            $targetConnectionName,
            [
                'pages',
                'tt_content',
                'sys_databasetransfer_import',
            ]
        );

        $databaseContext->runWithinConnection(function () {
            $this->assertCSVDataSet(__DIR__ . '/../Fixtures/DatabaseExports/pages-and-ttcontent-with-flexform-relation.csv');
        });
    }
}

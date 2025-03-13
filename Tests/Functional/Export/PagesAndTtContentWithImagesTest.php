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

class PagesAndTtContentWithImagesTest extends AbstractTransferTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DatabaseImports/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DatabaseImports/tt_content-with-image.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DatabaseImports/sys_file_metadata.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DatabaseImports/sys_file_reference.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DatabaseImports/sys_file_storage.csv');
    }

    #[Test]
    public function exportPagesAndRelatedTtContentWithImages(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DatabaseImports/sys_file.csv');
        GeneralUtility::makeInstance(ReferenceIndex::class)->updateIndex(false);

        $databaseContext = $this->runTransfer();

        $databaseContext->runWithinConnection(function () {
            $this->assertCSVDataSet(__DIR__ . '/../Fixtures/DatabaseExports/pages-and-ttcontent-with-image.csv');
        });
    }

    #[Test]
    public function exportPagesAndRelatedTtContentWithImagesFromCorruptSysFileRecord(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DatabaseImports/sys_file_corrupt.csv');
        GeneralUtility::makeInstance(ReferenceIndex::class)->updateIndex(false);

        // TODO: TYPO3 exports files by SHA1 and detects corrupt SHA1 in the DB.
        $databaseContext = $this->runTransfer();

        $databaseContext->runWithinConnection(function () {
            $this->assertCSVDataSet(__DIR__ . '/../Fixtures/DatabaseExports/pages-and-ttcontent-with-corrupt-image.csv');
        });
    }

    #[Test]
    public function exportPagesAndRelatedTtContentWithImagesButNotIncluded(): void
    {
        $this->markTestIncomplete(
            'Files export has not been implemented yet.'
        );

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DatabaseImports/sys_file.csv');
        $this->get(ReferenceIndex::class)->updateIndex(false);

        // TODO: implement setSaveFilesOutsideExportFile
        $databaseContext = $this->runTransfer();

        $databaseContext->runWithinConnection(function () {
            $this->assertCSVDataSet(__DIR__ . '/../Fixtures/DatabaseExports/pages-and-ttcontent-with-image-but-not-included.csv');
        });

        //self::assertFileEquals(__DIR__ . '/../Fixtures/Folders/fileadmin/user_upload/typo3_image2.jpg', $temporaryFilesDirectory . '/' . 'da9acdf1e105784a57bbffec9520969578287797');
    }

    private function runTransfer(): DatabaseContext
    {
        $targetConnectionName = $this->createSqliteConnection('export');

        $options = [
            'pid' => [1],
            'include-table' => [SelectionFactory::TABLES_ALL],
            'include-related' => ['sys_file', 'sys_file_metadata'],
            'include-static' => ['sys_file_storage'],
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
                'sys_file',
                'sys_file_metadata',
                'sys_databasetransfer_import',
            ]
        );

        return $databaseContext;
    }
}

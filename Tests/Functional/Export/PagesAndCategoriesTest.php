<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace Toujou\DatabaseTransfer\Tests\Functional\Export;

use PHPUnit\Framework\Attributes\Test;
use Toujou\DatabaseTransfer\Database\DatabaseContext;
use Toujou\DatabaseTransfer\Export\SelectionFactory;
use Toujou\DatabaseTransfer\Service\TransferService;
use Toujou\DatabaseTransfer\Tests\Functional\AbstractTransferTestCase;
use TYPO3\CMS\Core\Database\ReferenceIndex;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class PagesAndCategoriesTest extends AbstractTransferTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DatabaseImports/pages-categories.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DatabaseImports/sys_category.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DatabaseImports/sys_category_record_mm.csv');

        GeneralUtility::makeInstance(ReferenceIndex::class)->updateIndex(false);
    }

    #[Test]
    public function exportPagesAndCategories(): void
    {
        $targetConnectionName = $this->createSqliteConnection('export');

        $options = [
            'pid' => [10, 0],
            'include-table' => ['pages', 'sys_category'],
            // TODO test sys_category only within include-related
            //'include-related' => []
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
                'sys_category',
                'sys_category_record_mm',
                'sys_databasetransfer_import',
            ]
        );

        // tt_content:2 header_link field contains a reference to file:40 which is on the fallback storage and thus not part
        // of the reference index. As header_link is a link field, this reference is NOT cleared during export.
        $databaseContext->runWithinConnection(function () {
            $this->assertCSVDataSet(__DIR__ . '/../Fixtures/DatabaseExports/pages-and-categories.csv');
        });
    }
}

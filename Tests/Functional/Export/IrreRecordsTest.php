<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Tests\Functional\Export;

use PHPUnit\Framework\Attributes\Test;
use Toujou\DatabaseTransfer\Export\SelectionFactory;
use Toujou\DatabaseTransfer\Tests\Functional\AbstractTransferTestCase;

final class IrreRecordsTest extends AbstractTransferTestCase
{
    private string $targetConnectionName;

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

    }

    #[Test]
    public function importIrreRecords(): void
    {
        $options = [
            'pid' => [1],
            'include-table' => [SelectionFactory::TABLES_ALL],
        ];

        $this->runTransfer($options);
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/DatabaseExports/irre_records.csv');
    }
}

<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Tests\Functional\Export;

use PHPUnit\Framework\Attributes\Test;
use Toujou\DatabaseTransfer\Export\SelectionFactory;
use Toujou\DatabaseTransfer\Tests\Functional\AbstractTransferTestCase;

final class RecordsWithLinkFieldTest extends AbstractTransferTestCase
{
    protected function setUp(): void
    {
        $this->testExtensionsToLoad = [
            ...$this->testExtensionsToLoad,
            'typo3conf/ext/toujou_database_transfer/Tests/Functional/Fixtures/Extensions/test_link_field',
        ];

        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DatabaseImports/link_records.csv');
    }

    #[Test]
    public function importLinkRecords(): void
    {
        $options = [
            'pid' => [10],
            'include-table' => [SelectionFactory::TABLES_ALL],
        ];

        $this->runTransfer($options);
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/DatabaseExports/link_records.csv');
    }
}

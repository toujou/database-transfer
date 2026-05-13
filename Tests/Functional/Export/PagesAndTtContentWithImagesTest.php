<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Tests\Functional\Export;

use PHPUnit\Framework\Attributes\Test;
use Toujou\DatabaseTransfer\Export\SelectionFactory;
use Toujou\DatabaseTransfer\Tests\Functional\AbstractTransferTestCase;

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
        $options = [
            'pid' => [10],
            'include-table' => [SelectionFactory::TABLES_ALL],
            'include-related' => ['sys_file', 'sys_file_metadata'],
            'include-static' => ['sys_file_storage'],
        ];

        $this->runTransfer($options);
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/DatabaseExports/pages-and-ttcontent-with-image.csv');
    }

    #[Test]
    public function exportPagesAndRelatedTtContentWithImagesFromCorruptSysFileRecord(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DatabaseImports/sys_file_corrupt.csv');

        $options = [
            'pid' => [10],
            'include-table' => [SelectionFactory::TABLES_ALL],
            'include-related' => ['sys_file', 'sys_file_metadata'],
            'include-static' => ['sys_file_storage'],
        ];

        $this->runTransfer($options);
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/DatabaseExports/pages-and-ttcontent-with-corrupt-image.csv');
    }
}

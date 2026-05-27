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
use Toujou\DatabaseTransfer\Tests\Functional\AbstractTransferTestCase;

final class PagesAndTtContentTest extends AbstractTransferTestCase
{
    private string $targetConnectionName;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DatabaseImports/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DatabaseImports/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DatabaseImports/sys_file.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DatabaseImports/sys_file-export-pages-and-tt-content.csv');
    }

    #[Test]
    public function exportPagesAndRelatedTtContent(): void
    {
        $options = [
            'pid' => [10],
            'include-table' => ['pages', 'tt_content'],
        ];

        $this->runTransfer($options);

        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/DatabaseExports/pages-and-ttcontent.csv');
    }

    #[Test]
    public function exportPagesAndRelatedTtContentWithComplexConfiguration(): void
    {
        $options = [
            'pid' => [10],
            'exclude-record' => ['pages:20', 'tt_content:20'],
            'include-table' => ['pages', 'tt_content'],
            'include-related' => ['sys_file'],
        ];

        $this->runTransfer($options);

        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/DatabaseExports/pages-and-ttcontent-complex.csv');
    }
}

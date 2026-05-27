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

final class PagesAndCategoriesTest extends AbstractTransferTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DatabaseImports/pages-categories.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DatabaseImports/sys_category.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DatabaseImports/sys_category_record_mm.csv');
    }

    #[Test]
    public function exportPagesAndCategories(): void
    {

        $options = [
            'pid' => [10, 0],
            'include-table' => ['pages', 'sys_category'],
            // TODO test sys_category only within include-related
            //'include-related' => []
        ];
        $this->runTransfer($options);

        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/DatabaseExports/pages-and-categories.csv');
    }
}

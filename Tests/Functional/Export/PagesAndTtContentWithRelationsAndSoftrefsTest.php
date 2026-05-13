<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Tests\Functional\Export;

use PHPUnit\Framework\Attributes\Test;
use Toujou\DatabaseTransfer\Tests\Functional\AbstractTransferTestCase;
use TYPO3\CMS\Core\Database\ReferenceIndex;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class PagesAndTtContentWithRelationsAndSoftrefsTest extends AbstractTransferTestCase
{
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

        $options = [
            'pid' => [10],
            'include-table' => ['pages', 'tt_content'],
        ];

        $this->runTransfer($options);
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/DatabaseExports/pages-and-ttcontent-with-flexform-relation.csv');
    }
}

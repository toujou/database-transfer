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

namespace TYPO3\CMS\Impexp\Tests\Functional\Export;

use PHPUnit\Framework\Attributes\Test;
use Toujou\DatabaseTransfer\Database\DatabaseContext;
use Toujou\DatabaseTransfer\Database\FastImportConnection;
use Toujou\DatabaseTransfer\Export\SelectionFactory;
use Toujou\DatabaseTransfer\Service\Exporter;
use TYPO3\CMS\Core\Database\ReferenceIndex;
use TYPO3\CMS\Impexp\Export;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PagesAndTtContentTest extends FunctionalTestCase
{

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/toujou_database_transfer',
    ];
    private string $connnectionName;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DatabaseImports/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DatabaseImports/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DatabaseImports/sys_file.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DatabaseImports/sys_file-export-pages-and-tt-content.csv');

        $this->get(ReferenceIndex::class)->updateIndex(false);

        $this->exportConnnectionName = uniqid('export_', false);
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections'][$this->exportConnnectionName] = [
            'url' => 'pdo-sqlite:///typo3temp/var/transient/' . $this->exportConnnectionName . '.sqlite',
            'driver' => '',
            'wrapperClass' => FastImportConnection::class,
        ];
    }

    protected function tearDown(): void
    {
        if (file_exists($this->instancePath . '/typo3temp/var/transient/' . $this->exportConnnectionName . '.sqlite')
            && is_file($this->instancePath . '/typo3temp/var/transient/' . $this->exportConnnectionName . '.sqlite')
        ) {
            @unlink($this->instancePath . '/typo3temp/var/transient/' . $this->exportConnnectionName . '.sqlite');
        }
        parent::tearDown();
    }

    #[Test]
    public function exportPagesAndRelatedTtContent(): void
    {
        $options = [
            'pid' => [1],
            'include-table' => ['pages', 'tt_content'],
        ];

        $selectionFactory = $this->get(SelectionFactory::class);
        $selection = $selectionFactory->buildFromCommandOptions($options);

        $exporter = $this->get(Exporter::CLASS);
        $exporter->export($selection, $this->exportConnnectionName);

        $databaseContext = new DatabaseContext(
            $this->getConnectionPool()->getConnectionByName($this->exportConnnectionName),
            $this->exportConnnectionName,
            [
                'pages',
                'tt_content',
            ]
        );

        // tt_content:2 header_link field contains a reference to file:4 which is on the fallback storage and thus not part
        // of the reference index. As header_link is a link field, this reference is NOT cleared during export.
        $databaseContext->runWithinConnection(function() {
            $this->assertCSVDataSet(__DIR__ . '/../Fixtures/DatabaseExports/pages-and-ttcontent.csv');
        });
    }

    #[Test]
    public function exportPagesAndRelatedTtContentWithComplexConfiguration(): void
    {
        $options = [
            'pid' => [1],
            'exclude-record' => ['pages:2', 'tt_content:2'],
            'include-table' => ['pages', 'tt_content'],
            'include-related' => ['sys_file'],
        ];

        $selectionFactory = $this->get(SelectionFactory::class);
        $selection = $selectionFactory->buildFromCommandOptions($options);

        $exporter = $this->get(Exporter::CLASS);
        $exporter->export($selection, $this->exportConnnectionName);

        $databaseContext = new DatabaseContext(
            $this->getConnectionPool()->getConnectionByName($this->exportConnnectionName),
            $this->exportConnnectionName,
            [
                'pages',
                'tt_content',
                'sys_file',
            ]
        );

        // tt_content:2 header_link field contains a reference to file:4 which is on the fallback storage and thus not part
        // of the reference index. As header_link is a link field, this reference is NOT cleared during export.
        $databaseContext->runWithinConnection(function() {
            $this->assertCSVDataSet(__DIR__ . '/../Fixtures/DatabaseExports/pages-and-ttcontent-complex.csv');
        });
    }
}

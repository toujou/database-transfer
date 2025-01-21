<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Tests\Functional;

use Toujou\DatabaseTransfer\Database\FastImportConnection;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

abstract class AbstractExportTestCase extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/toujou_database_transfer',
    ];

    protected array $testFilesToDelete = [];

    protected function createSqliteConnection(string $prefix): string
    {
        $exportConnnectionName = uniqid($prefix . '_', false);
        // For yet unknown reason this has to be an absolute path. Probably some working directory issues switching in the test framework.
        $fileName = $this->instancePath . '/typo3temp/var/transient/' . $exportConnnectionName . '.sqlite';
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections'][$exportConnnectionName] = [
            'url' => 'pdo-sqlite:///' . $fileName,
            'driver' => '',
            'wrapperClass' => FastImportConnection::class,
        ];
        $this->testFilesToDelete[] = $fileName;

        return $exportConnnectionName;
    }

    protected function tearDown(): void
    {
        foreach ($this->testFilesToDelete as $absoluteFileName) {
            if (@is_file($absoluteFileName)) {
                unlink($absoluteFileName);
            }
        }
        parent::tearDown();
    }
}

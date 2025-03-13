<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Tests\Functional;

use Toujou\DatabaseTransfer\Database\FastImportConnection;
use Toujou\DatabaseTransfer\Service\SchemaService;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

abstract class AbstractTransferTestCase extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'core'
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/toujou_database_transfer',
    ];

    protected array $testFilesToDelete = [];

    protected function createSqliteConnection(string $filePrefix): string
    {
        $connectionName = uniqid( '', false);
        // For yet unknown reason this has to be an absolute path. Probably some working directory issues switching in the test framework.
        $fileName = $this->instancePath . '/typo3temp/var/transient/' . $filePrefix . '_' . $connectionName . '.sqlite';
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections'][$connectionName] = [
            'url' => 'pdo-sqlite:///' . $fileName,
            'driver' => '',
            'wrapperClass' => FastImportConnection::class,
        ];
        $this->testFilesToDelete[] = $fileName;

        return $connectionName;
    }

    protected function renameImportIndexToWellKnownTableName(Connection $targetConnection, string $targetConnectionName): void
    {
        $schemaService = $this->get(SchemaService::class);
        $schemaService->renameTable($targetConnection, 'sys_databasetransfer_import', $schemaService->getIndexTableName('import', $targetConnectionName));
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

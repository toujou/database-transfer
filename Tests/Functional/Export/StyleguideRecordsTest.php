<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Tests\Functional\Export;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use Toujou\DatabaseTransfer\Database\DatabaseContext;
use Toujou\DatabaseTransfer\Export\SelectionFactory;
use Toujou\DatabaseTransfer\Service\TransferService;
use Toujou\DatabaseTransfer\Tests\Functional\AbstractTransferTestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Styleguide\TcaDataGenerator\Generator;

final class StyleguideRecordsTest extends AbstractTransferTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/styleguide',
        'typo3conf/ext/toujou_database_transfer',
    ];

    #[Test]
    public function exportStyleguideRecords(): void
    {
        $request = $this->createServerRequest('https://typo3-testing.local/typo3/');
        Bootstrap::initializeBackendUser(BackendUserAuthentication::class, $request);
        $GLOBALS['BE_USER']->user['username'] = 'acceptanceTestSetup';
        $GLOBALS['BE_USER']->user['admin'] = 1;
        $GLOBALS['BE_USER']->user['uid'] = 1;
        $GLOBALS['BE_USER']->workspace = 0;
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);

        $styleguideGenerator = GeneralUtility::makeInstance(Generator::class);
        $styleguideGenerator->create();

        $targetConnectionName = $this->createSqliteConnection('export');

        $options = [
            'pid' => [1],
            'include-table' => [SelectionFactory::TABLES_ALL],
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
                'sqlite_master',
                'sqlite_sequence',
                'sys_databasetransfer_import',
                'sys_file_reference',
                'sys_refindex',
                'tx_styleguide_ctrl_common',
                'tx_styleguide_ctrl_minimal',
                'tx_styleguide_displaycond',
                'tx_styleguide_elements_basic',
                'tx_styleguide_elements_folder',
                'tx_styleguide_elements_group',
                'tx_styleguide_elements_imagemanipulation',
                'tx_styleguide_elements_rte',
                'tx_styleguide_elements_rte_flex_1_inline_1_child',
                'tx_styleguide_elements_rte_inline_1_child',
                'tx_styleguide_elements_select',
                'tx_styleguide_elements_select_single_12_foreign',
                'tx_styleguide_elements_slugs',
                'tx_styleguide_elements_t3editor',
                'tx_styleguide_elements_t3editor_flex_1_inline_1_child',
                'tx_styleguide_elements_t3editor_inline_1_child',
                'tx_styleguide_file',
                'tx_styleguide_flex',
                'tx_styleguide_flex_flex_3_inline_1_child',
                'tx_styleguide_inline_1n',
                'tx_styleguide_inline_1n1n',
                'tx_styleguide_inline_1n1n_child',
                'tx_styleguide_inline_1n1n_childchild',
                'tx_styleguide_inline_1n_inline_1_child',
                'tx_styleguide_inline_1n_inline_2_child',
                'tx_styleguide_inline_1nnol10n',
                'tx_styleguide_inline_1nnol10n_child',
                'tx_styleguide_inline_11',
                'tx_styleguide_inline_expand',
                'tx_styleguide_inline_expand_inline_1_child',
                'tx_styleguide_inline_expandsingle',
                'tx_styleguide_inline_expandsingle_child',
                'tx_styleguide_inline_foreignrecorddefaults',
                'tx_styleguide_inline_foreignrecorddefaults_child',
                'tx_styleguide_inline_mm',
                'tx_styleguide_inline_mn',
                'tx_styleguide_inline_mn_child',
                'tx_styleguide_inline_mn_mm',
                'tx_styleguide_inline_mngroup',
                'tx_styleguide_inline_mngroup_child',
                'tx_styleguide_inline_mngroup_mm',
                'tx_styleguide_inline_mnsymmetric',
                'tx_styleguide_inline_mnsymmetric_mm',
                'tx_styleguide_inline_mnsymmetricgroup',
                'tx_styleguide_inline_mnsymmetricgroup_mm',
                'tx_styleguide_inline_parentnosoftdelete',
                'tx_styleguide_inline_usecombination',
                'tx_styleguide_inline_usecombination_child',
                'tx_styleguide_inline_usecombination_mm',
                'tx_styleguide_inline_usecombinationbox',
                'tx_styleguide_inline_usecombinationbox_child',
                'tx_styleguide_inline_usecombinationbox_mm',
                'tx_styleguide_l10nreadonly',
                'tx_styleguide_l10nreadonly_inline_child',
                'tx_styleguide_palette',
                'tx_styleguide_required',
                'tx_styleguide_required_flex_2_inline_1_child',
                'tx_styleguide_required_inline_1_child',
                'tx_styleguide_required_inline_2_child',
                'tx_styleguide_required_inline_3_child',
                'tx_styleguide_required_rte_2_child',
                'tx_styleguide_staticdata',
                'tx_styleguide_type',
                'tx_styleguide_typeforeign',
                'tx_styleguide_valuesdefault',
            ]
        );

        $databaseContext->runWithinConnection(function () {
            $this->assertCSVDataSet(__DIR__ . '/../Fixtures/DatabaseExports/styleguide.csv');
        });
    }

    private function createServerRequest(string $url, string $method = 'GET'): ServerRequestInterface
    {
        $requestUrlParts = parse_url($url);
        $docRoot = getenv('TYPO3_PATH_APP') ?? '';
        $serverParams = [
            'DOCUMENT_ROOT' => $docRoot,
            'HTTP_USER_AGENT' => 'TYPO3 Functional Test Request',
            'HTTP_HOST' => $requestUrlParts['host'] ?? 'localhost',
            'SERVER_NAME' => $requestUrlParts['host'] ?? 'localhost',
            'SERVER_ADDR' => '127.0.0.1',
            'REMOTE_ADDR' => '127.0.0.1',
            'SCRIPT_NAME' => '/index.php',
            'PHP_SELF' => '/index.php',
            'SCRIPT_FILENAME' => $docRoot . '/index.php',
            'QUERY_STRING' => $requestUrlParts['query'] ?? '',
            'REQUEST_URI' => $requestUrlParts['path'] . (isset($requestUrlParts['query']) ? '?' . $requestUrlParts['query'] : ''),
            'REQUEST_METHOD' => $method,
        ];
        // Define HTTPS and server port
        if (isset($requestUrlParts['scheme'])) {
            if ('https' === $requestUrlParts['scheme']) {
                $serverParams['HTTPS'] = 'on';
                $serverParams['SERVER_PORT'] = '443';
            } else {
                $serverParams['SERVER_PORT'] = '80';
            }
        }

        // Define a port if used in the URL
        if (isset($requestUrlParts['port'])) {
            $serverParams['SERVER_PORT'] = $requestUrlParts['port'];
        }
        // set up normalizedParams
        $request = new ServerRequest($url, $method, null, [], $serverParams);

        return $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }
}

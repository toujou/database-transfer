<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Tests\Functional\Export;

use PHPUnit\Framework\Attributes\Test;
use Toujou\DatabaseTransfer\Export\SelectionFactory;
use Toujou\DatabaseTransfer\Tests\Functional\AbstractTransferTestCase;

final class PagesAndTtContentMultilingualTest extends AbstractTransferTestCase
{
    private const EXPORT_DIR = __DIR__ . '/../Fixtures/DatabaseExports/';
    private const IMPORT_DIR = __DIR__ . '/../Fixtures/DatabaseImports/';

    #[Test]
    public function exportWithoutLanguageMapOnlyImportsDefaultLanguage(): void
    {
        $this->importMultilingualFixtures();

        $this->runTransfer([
            'pid' => [10],
            'include-table' => ['pages', 'tt_content'],
        ]);

        $this->assertCSVDataSet(self::EXPORT_DIR . 'pages-and-ttcontent-multilingual-default.csv');
    }

    #[Test]
    public function exportWithIdentityLanguageMapImportsTranslations(): void
    {
        $this->importMultilingualFixtures();

        $this->runTransfer([
            'pid' => [10],
            'include-table' => ['pages', 'tt_content'],
            'language-map' => ['1,2'],
        ]);

        $this->assertCSVDataSet(self::EXPORT_DIR . 'pages-and-ttcontent-multilingual-identity.csv');
    }

    #[Test]
    public function exportWithRemappedLanguageMapRemapsLanguageIds(): void
    {
        $this->importMultilingualFixtures();

        $this->runTransfer([
            'pid' => [10],
            'include-table' => ['pages', 'tt_content'],
            'language-map' => ['1:10'],
        ]);

        $this->assertCSVDataSet(self::EXPORT_DIR . 'pages-and-ttcontent-multilingual-remap.csv');
    }

    #[Test]
    public function exportWithUnmappedParentFallsBackToZero(): void
    {
        $this->importMultilingualFixtures();

        $this->runTransfer([
            'pid' => [10],
            'include-table' => ['pages', 'tt_content'],
            'exclude-record' => ['tt_content:30'],
            'language-map' => ['1'],
        ]);

        $this->assertCSVDataSet(self::EXPORT_DIR . 'pages-and-ttcontent-multilingual-unmapped-parent.csv');
    }

    #[Test]
    public function exportWithFreeModeContentOnSelectedPid(): void
    {
        $this->importMultilingualFixtures();
        $this->importCSVDataSet(self::IMPORT_DIR . 'tt_content-free-mode.csv');

        $this->runTransfer([
            'pid' => [10],
            'include-table' => ['pages', 'tt_content'],
            'language-map' => ['1'],
        ]);

        $this->assertCSVDataSet(self::EXPORT_DIR . 'pages-and-ttcontent-multilingual-free-mode.csv');
    }

    #[Test]
    public function exportWithoutLanguageMapOmitsFreeModeContent(): void
    {
        $this->importMultilingualFixtures();
        $this->importCSVDataSet(self::IMPORT_DIR . 'tt_content-free-mode.csv');

        $this->runTransfer([
            'pid' => [10],
            'include-table' => ['pages', 'tt_content'],
        ]);

        $this->assertCSVDataSet(self::EXPORT_DIR . 'pages-and-ttcontent-multilingual-default.csv');
    }

    #[Test]
    public function exportImportsMetadataOverlay(): void
    {
        $this->importCSVDataSet(self::IMPORT_DIR . 'pages.csv');
        $this->importCSVDataSet(self::IMPORT_DIR . 'tt_content-with-image.csv');
        $this->importCSVDataSet(self::IMPORT_DIR . 'sys_file_metadata.csv');
        $this->importCSVDataSet(self::IMPORT_DIR . 'sys_file_reference.csv');
        $this->importCSVDataSet(self::IMPORT_DIR . 'sys_file_storage.csv');
        $this->importCSVDataSet(self::IMPORT_DIR . 'sys_file.csv');

        $this->runTransfer([
            'pid' => [10],
            'include-table' => [SelectionFactory::TABLES_ALL],
            'include-related' => ['sys_file', 'sys_file_metadata'],
            'include-static' => ['sys_file_storage'],
            'language-map' => ['1'],
        ]);

        $this->assertCSVDataSet(self::EXPORT_DIR . 'pages-and-ttcontent-with-image-multilingual.csv');
    }

    private function importMultilingualFixtures(): void
    {
        $this->importCSVDataSet(self::IMPORT_DIR . 'pages-multilingual.csv');
        $this->importCSVDataSet(self::IMPORT_DIR . 'tt_content-multilingual.csv');
    }
}

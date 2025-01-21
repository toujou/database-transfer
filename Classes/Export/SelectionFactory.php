<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Export;

use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Site\SiteFinder;

class SelectionFactory
{
    public const TABLES_ALL = 'ALL';

    public const DEPTH_MAX = 100;

    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly PageRepository $pageRepository
    ) {
    }

    public function buildFromCommandOptions(array $options): Selection
    {
        $excludedRecords = $this->getExcludedRecords($options['exclude-record'] ?? []);
        $pageIds = $this->getPageIds($options['site'] ?? null, $options['pid'] ?? [], $excludedRecords['pages'] ?? []);
        $includesRootLevel = \in_array(0, $pageIds);
        $selectedTables = $this->getTableSelection($options['include-table'] ?? [], $options['exclude-table'] ?? [], $includesRootLevel);
        $relatedTables = $this->getTableSelection($options['include-related'] ?? [], \array_merge($options['exclude-table'] ?? [], $options['include-static'] ?? []));
        $staticTables = $this->getTableSelection($options['include-static'] ?? [], \array_merge($options['exclude-table'] ?? [], $options['include-related'] ?? []));

        return new Selection($pageIds, $selectedTables, $relatedTables, $staticTables, $excludedRecords);
    }

    private function getPageIds(string $siteIdentifier = null, array $pid = [], array $excludedPageIds = []): array
    {
        $rootPageIds = [];

        if (null !== $siteIdentifier) {
            $rootPageIds[] = [$this->siteFinder->getSiteByIdentifier($siteIdentifier)->getRootPageId(), self::DEPTH_MAX];
        }

        foreach ($pid as $pageId) {
            [$pageId, $depth] = explode(':', (string) $pageId) + [null, self::DEPTH_MAX];
            $pageId = (int) $pageId;
            $depth = (int) $depth;
            if (0 === $pageId || (!in_array($pageId, $excludedPageIds) && $this->pageRepository->getPage_noCheck($pageId))) {
                $rootPageIds[] = [$pageId, $depth];
            }
        }

        $pageIds = [];
        foreach ($rootPageIds as [$pageId, $depth]) {
            $pageIds = \array_merge(
                $pageIds,
                [$pageId],
                $this->pageRepository->getDescendantPageIdsRecursive($pageId, $depth, 0, $excludedPageIds, true)
            );
        }

        return \array_unique($pageIds);
    }

    private function getTableSelection(array $includedTables = [], array $excludeTables = [], bool $includeRootLevel = true): array
    {
        if (\in_array(self::TABLES_ALL, $includedTables, true)) {
            unset($includedTables[\array_search(self::TABLES_ALL, $includedTables, true)]);
            $includedTables = \array_merge(\array_filter(
                \array_keys($GLOBALS['TCA']),
                fn (string $tableName) => \in_array($GLOBALS['TCA'][$tableName]['ctrl']['rootLevel'] ?? 0, [0, -1]) || $includeRootLevel
            ), $includedTables);
        } else {
            $includedTables = \array_filter($includedTables, fn (string $tableName) => \array_key_exists($tableName, $GLOBALS['TCA']));
        }

        return \array_filter($includedTables, fn ($tableName) => !\in_array($tableName, $excludeTables, true));
    }

    private function getExcludedRecords(array $excludedRecords = [])
    {
        $parsedExcludedRecords = [];
        foreach ($excludedRecords as $excludedRecord) {
            [$tableName, $uid] = explode(':', (string) $excludedRecord) + [null, 0];
            $uid = (int) $uid;
            if (\array_key_exists($tableName, $GLOBALS['TCA'])) {
                $parsedExcludedRecords[$tableName][$uid] = $uid;
            }
        }

        return $parsedExcludedRecords;
    }
}

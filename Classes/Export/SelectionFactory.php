<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Export;

use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

class SelectionFactory
{
    public const TABLES_ALL = 'ALL';

    public const DEPTH_MAX = 100;

    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly PageRepository $pageRepository,
    ) {}

    /**
     * @param mixed[] $options
     */
    public function buildFromCommandOptions(array $options): Selection
    {
        $excludedRecords = $this->getExcludedRecords($options['exclude-record'] ?? []);

        $site = $options['site'] ?? null;
        $pages = $options['pid'] ?? [];

        if ($options['all'] ?? null) {
            $pages = [
                0,
                ...array_map(fn(Site $site) => $site->getRootPageId(), $this->siteFinder->getAllSites()),
            ];
        }

        $pageIds = $this->getPageIds($site, $pages ?? [], $excludedRecords['pages'] ?? []);
        $includesRootLevel = \in_array(0, $pageIds);
        $selectedTables = $this->getTableSelection($options['include-table'] ?? [], $options['exclude-table'] ?? [], $includesRootLevel);
        $relatedTables = $this->getTableSelection($options['include-related'] ?? [], \array_merge($options['exclude-table'] ?? [], $options['include-static'] ?? []));
        $staticTables = $this->getTableSelection($options['include-static'] ?? [], \array_merge($options['exclude-table'] ?? [], $options['include-related'] ?? []));

        return new Selection($pageIds, $selectedTables, $relatedTables, $staticTables, $excludedRecords);
    }

    /**
     * @param int[] $pid
     * @param int[] $excludedPageIds
     *
     * @return int[]
     *
     * @throws \TYPO3\CMS\Core\Exception\SiteNotFoundException
     */
    private function getPageIds(string $siteIdentifier = null, array $pid = [], array $excludedPageIds = []): array
    {
        $rootPageIds = [];

        if ($siteIdentifier !== null) {
            $rootPageIds[] = [$this->siteFinder->getSiteByIdentifier($siteIdentifier)->getRootPageId(), self::DEPTH_MAX];
        }

        foreach ($pid as $pageId) {
            [$pageId, $depth] = explode(':', (string)$pageId) + [null, self::DEPTH_MAX];
            $pageId = (int)$pageId;
            $depth = (int)$depth;
            if ($pageId === 0 || (!in_array($pageId, $excludedPageIds) && $this->pageRepository->getPage_noCheck($pageId))) {
                $rootPageIds[] = [$pageId, $depth];
            }
        }

        $pageIds = [];
        foreach ($rootPageIds as [$pageId, $depth]) {
            $pageIds = \array_merge(
                $pageIds,
                [$pageId],
                $this->pageRepository->getDescendantPageIdsRecursive($pageId, $depth, 0, $excludedPageIds, true),
            );
        }

        return \array_unique($pageIds);
    }

    /**
     * @param string[] $includedTables
     * @param string[] $excludeTables
     *
     * @return string[]
     */
    private function getTableSelection(array $includedTables = [], array $excludeTables = [], bool $includeRootLevel = true): array
    {
        if (\in_array(self::TABLES_ALL, $includedTables, true)) {
            unset($includedTables[\array_search(self::TABLES_ALL, $includedTables, true)]);
            $includedTables = \array_merge(\array_filter(
                \array_keys($GLOBALS['TCA']),
                fn(string $tableName) => \in_array($GLOBALS['TCA'][$tableName]['ctrl']['rootLevel'] ?? 0, [0, -1]) || $includeRootLevel,
            ), $includedTables);
        } else {
            $includedTables = \array_filter($includedTables, fn(string $tableName) => \array_key_exists($tableName, $GLOBALS['TCA']));
        }

        return \array_filter($includedTables, fn($tableName) => !\in_array($tableName, $excludeTables, true));
    }

    /**
     * @param mixed[] $excludedRecords
     *
     * @return mixed[]
     */
    private function getExcludedRecords(array $excludedRecords = []): array
    {
        $parsedExcludedRecords = [];
        foreach ($excludedRecords as $excludedRecord) {
            [$tableName, $uid] = explode(':', (string)$excludedRecord) + [null, 0];
            $uid = (int)$uid;
            if (\array_key_exists($tableName, $GLOBALS['TCA'])) {
                $parsedExcludedRecords[$tableName][$uid] = $uid;
            }
        }

        return $parsedExcludedRecords;
    }
}

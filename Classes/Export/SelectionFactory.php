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
        $pageIds = $this->getPageIds($options['site'] ?? null, $options['pid'] ?? []);
        $includesRootLevel = \in_array(0, $pageIds);
        $selectedTables = $this->getTableSelection($options['include-table'] ?? [], $options['exclude-table'] ?? [], $includesRootLevel);
        $relatedTables = $this->getTableSelection($options['include-related'] ?? [], \array_merge($options['exclude-table'] ?? [], $options['include-static'] ?? []));
        $staticTables = $this->getTableSelection($options['include-static'] ?? [], \array_merge($options['exclude-table'] ?? [], $options['include-related'] ?? []));

        return new Selection($pageIds, $selectedTables, $relatedTables, $staticTables);
    }

    private function getPageIds(string $siteIdentifier = null, array $pid = []): array
    {
        $rootPageIds = [];

        if (null !== $siteIdentifier) {
            $rootPageIds[] = [$this->siteFinder->getSiteByIdentifier($siteIdentifier)->getRootPageId(), self::DEPTH_MAX];
        }

        foreach ($pid as $pageId) {
            [$pageId, $depth] = explode(':', $pageId) + [null, self::DEPTH_MAX];
            $pageId = (int) $pageId;
            $depth = (int) $depth;
            if (0 === $pageId || $this->pageRepository->getPage_noCheck($pageId)) {
                $rootPageIds[] = [$pageId, $depth];
            }
        }

        $pageIds = [];
        foreach ($rootPageIds as [$pageId, $depth]) {
            $pageIds = \array_merge(
                $pageIds,
                [$pageId],
                $this->pageRepository->getDescendantPageIdsRecursive($pageId, $depth, 0, [], true)
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
}

<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Export;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

class SelectionFactory
{
    public const TABLES_ALL = 'ALL';

    public const DEPTH_MAX = 100;

    public function __construct(
        private readonly ConnectionPool $connectionPool
    ) {}

    /**
     * @param mixed[] $options
     */
    public function buildFromCommandOptions(array $options): Selection
    {
        $excludedRecords = $this->getExcludedRecords($options['exclude-record'] ?? []);

        $pages = $options['all'] ?? false ? [0] : ($options['pid'] ?? []);
        $pageIds = $this->getPageIds( $pages ?? [], $excludedRecords['pages'] ?? []);
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
    private function getPageIds(array $pid = [], array $excludedPageIds = []): array
    {
        $rootPageIds = [];

        foreach ($pid as $pageId) {
            [$pageId, $depth] = explode(':', (string)$pageId) + [null, self::DEPTH_MAX];
            $pageId = (int)$pageId;
            $depth = (int)$depth;
            if ($pageId === 0 || !in_array($pageId, $excludedPageIds)) {
                $rootPageIds[] = [$pageId, $depth];
            }
        }

        $pageIds = [];

        foreach ($rootPageIds as [$pageId, $depth]) {
            $pageIds[] = $pageId;

            $connection = $this->connectionPool->getConnectionForTable('pages');

            $pageUidsQuery = <<<SQL
                WITH RECURSIVE page_tree AS (
                    -- base case
                    SELECT
                        uid,
                        pid,
                        0 AS depth
                    FROM pages
                    WHERE pid = :root_id
                    AND deleted = 0
                    AND sys_language_uid = 0
                    AND uid NOT IN (:excludePageIds)

                    UNION ALL

                    -- recursive step
                    SELECT
                        p.uid,
                        p.pid,
                        pt.depth + 1
                    FROM pages p
                    INNER JOIN page_tree pt
                        ON p.pid = pt.uid
                    WHERE pt.depth < :max_depth
                    AND deleted = 0
                    AND sys_language_uid = 0
                    AND p.uid NOT IN (:excludePageIds)
                )

                SELECT uid
                FROM page_tree;
SQL;
            $subPageUids = $connection->fetchFirstColumn(
                $pageUidsQuery,
                [
                    'root_id' => $pageId,
                    'max_depth' => $depth,
                    'excludePageIds' =>  $excludedPageIds ?: [-1]
                ],
                [
                    'root_id' => Connection::PARAM_INT,
                    'max_depth' => Connection::PARAM_INT,
                    'excludePageIds' => Connection::PARAM_INT_ARRAY,
                ]
            );
            array_push($pageIds, ...$subPageUids);
        }

        return array_values(array_unique($pageIds));
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

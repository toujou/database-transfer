<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Export;

final class Selection
{
    /**
     * @param int[] $selectedPageIds
     * @param string[] $selectedTables
     * @param string[] $relatedTables
     * @param string[] $staticTables
     * @param mixed[] $excludedRecords
     */
    public function __construct(
        private readonly array $selectedPageIds = [],
        private readonly array $selectedTables = [],
        private readonly array $relatedTables = [],
        private readonly array $staticTables = [],
        private readonly array $excludedRecords = [],
    ) {}

    /**
     * @return int[]
     */
    public function getSelectedPageIds(): array
    {
        return $this->selectedPageIds;
    }

    /**
     * @return string[]
     */
    public function getSelectedTables(): array
    {
        return $this->selectedTables;
    }

    /**
     * @return string[]
     */
    public function getRelatedTables(): array
    {
        return $this->relatedTables;
    }

    /**
     * @return string[]
     */
    public function getStaticTables(): array
    {
        return $this->staticTables;
    }

    /**
     * @return mixed[]
     */
    public function getExcludedRecords(): array
    {
        return $this->excludedRecords;
    }
}

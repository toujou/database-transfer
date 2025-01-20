<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Export;

final class Selection
{
    public function __construct(
        private readonly array $selectedPageIds = [],
        private readonly array $selectedTables = [],
        private readonly array $relatedTables = [],
        private readonly array $staticTables = [],
        private readonly array $excludedRecords = []
    ) {
    }

    public function getSelectedPageIds(): array
    {
        return $this->selectedPageIds;
    }

    public function getSelectedTables(): array
    {
        return $this->selectedTables;
    }

    public function getRelatedTables(): array
    {
        return $this->relatedTables;
    }

    public function getStaticTables(): array
    {
        return $this->staticTables;
    }

    public function getExcludedRecords(): array
    {
        return $this->excludedRecords;
    }

}

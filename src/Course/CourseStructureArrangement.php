<?php

declare(strict_types=1);

namespace CattoLearning\Course;

use InvalidArgumentException;

/**
 * Ordered Course Content hierarchy operations. Rows are `{id, parent_node_id}` in tree order: each
 * row is followed by its descendants. Every operation returns a new, valid tree or throws; nothing
 * here touches the database, so CourseItemService validates a move in full before it persists it.
 */
final class CourseStructureArrangement
{
    public const MAX_DEPTH = 3;
    public const DIRECTIONS = ['top', 'up', 'down', 'bottom', 'out'];

    /** @param list<array<string,mixed>> $structure
     * @return list<array{id:int,parent_node_id:int|null}> */
    public function fromStructure(array $structure): array
    {
        return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'parent_node_id' => $row['parent_node_id'] === null ? null : (int) $row['parent_node_id']], $structure);
    }

    /**
     * Places the given rows, each with its whole subtree, under $parentId at $index among the
     * parent's remaining children (null appends). Selected rows inside another selected row travel
     * with that ancestor. The rows keep their current relative order.
     *
     * @param list<array{id:int,parent_node_id:int|null}> $rows
     * @param list<int> $nodeIds
     * @return list<array{id:int,parent_node_id:int|null}>
     */
    public function place(array $rows, array $nodeIds, ?int $parentId, ?int $index = null): array
    {
        [$byId, $children] = $this->index($rows);
        $moving = $this->outermost($rows, $byId, $nodeIds);
        if ($parentId !== null) {
            if (!isset($byId[$parentId])) { throw new InvalidArgumentException('The destination is no longer in this course.'); }
            foreach ($moving as $id) {
                if ($this->isWithin($byId, $parentId, $id)) { throw new InvalidArgumentException('A row cannot be moved inside itself or one of its own rows.'); }
            }
        }
        $height = max(array_map(fn(int $id): int => $this->height($children, $id), $moving));
        if ($this->depth($byId, $parentId) + $height > self::MAX_DEPTH) { throw new InvalidArgumentException('Course Content may have at most ' . self::MAX_DEPTH . ' levels.'); }
        foreach ($moving as $id) {
            $from = $byId[$id]['parent_node_id'] ?? 0;
            $children[$from] = array_values(array_diff($children[$from], [$id]));
        }
        $target = $children[$parentId ?? 0] ?? [];
        $index ??= count($target);
        if ($index < 0 || $index > count($target)) { throw new InvalidArgumentException('Choose a valid position.'); }
        array_splice($target, $index, 0, $moving);
        $children[$parentId ?? 0] = $target;
        foreach ($moving as $id) { $byId[$id]['parent_node_id'] = $parentId; }
        return $this->walk($byId, $children);
    }

    /**
     * Top, up, down and bottom move within the current parent; out places a row immediately after
     * its former parent. Moving a selection treats each outermost selected row in turn, so a group
     * keeps its order and a row blocked by the edge does not swap with a selected neighbour.
     *
     * @param list<array{id:int,parent_node_id:int|null}> $rows
     * @param list<int> $nodeIds
     * @return list<array{id:int,parent_node_id:int|null}>
     */
    public function move(array $rows, array $nodeIds, string $direction): array
    {
        if (!in_array($direction, self::DIRECTIONS, true)) { throw new InvalidArgumentException('Select a valid Course Content move.'); }
        [$byId] = $this->index($rows);
        $moving = $this->outermost($rows, $byId, $nodeIds);
        if ($direction === 'out') {
            foreach ($moving as $id) {
                if ($byId[$id]['parent_node_id'] === null) { throw new InvalidArgumentException('This row is already at the outer level.'); }
            }
        }
        $selected = array_fill_keys($moving, true);
        $order = in_array($direction, ['down', 'top', 'out'], true) ? array_reverse($moving) : $moving;
        foreach ($order as $id) {
            [$byId, $children] = $this->index($rows);
            $parent = $byId[$id]['parent_node_id'];
            $siblings = $children[$parent ?? 0];
            $at = (int) array_search($id, $siblings, true);
            $destination = $parent; $index = match ($direction) {
                'top' => 0,
                'bottom' => null,
                'up' => $at > 0 && !isset($selected[$siblings[$at - 1]]) ? $at - 1 : $at,
                'down' => isset($siblings[$at + 1]) && !isset($selected[$siblings[$at + 1]]) ? $at + 1 : $at,
                default => null,
            };
            if ($direction === 'out') {
                $destination = $byId[(int) $parent]['parent_node_id'];
                $index = (int) array_search($parent, $children[$destination ?? 0], true) + 1;
            }
            // A row at the edge stays selected, so the selected row behind it does not jump past it.
            if ($index === $at && $destination === $parent) { continue; }
            $rows = $this->place($rows, [$id], $destination, $index);
            unset($selected[$id]);
        }
        return $rows;
    }

    /**
     * Destinations that can take the given rows: the outer level (null) and every row that is not
     * one of them or inside them and leaves room for their deepest descendant.
     *
     * @param list<array{id:int,parent_node_id:int|null}> $rows
     * @param list<int> $nodeIds
     * @return list<int|null>
     */
    public function destinations(array $rows, array $nodeIds): array
    {
        [$byId, $children] = $this->index($rows);
        $moving = $this->outermost($rows, $byId, $nodeIds);
        $height = max(array_map(fn(int $id): int => $this->height($children, $id), $moving));
        $valid = [null];
        foreach ($rows as $row) {
            $id = $row['id'];
            $inside = array_filter($moving, fn(int $moved): bool => $this->isWithin($byId, $id, $moved)) !== [];
            if (!$inside && $this->depth($byId, $id) + $height <= self::MAX_DEPTH) { $valid[] = $id; }
        }
        return $valid;
    }

    /**
     * Confirms that $rows is a complete, acyclic arrangement of exactly the nodes in $structure,
     * in tree order and within the depth limit.
     *
     * @param list<array<string,mixed>> $structure
     * @param list<array{id:int,parent_node_id:int|null}> $rows
     */
    public function assertValid(array $structure, array $rows): void
    {
        $known = array_fill_keys(array_map(static fn(array $row): int => (int) $row['id'], $structure), true);
        if (count($known) !== count($rows)) { throw new InvalidArgumentException('Course Content changed while you were moving it. Reload the page and try again.'); }
        $depths = [];
        foreach ($rows as $row) {
            $id = $row['id']; $parent = $row['parent_node_id'];
            if (!isset($known[$id]) || isset($depths[$id]) || ($parent !== null && !isset($depths[$parent]))) { throw new InvalidArgumentException('Course Content changed while you were moving it. Reload the page and try again.'); }
            $depths[$id] = $parent === null ? 1 : $depths[$parent] + 1;
            if ($depths[$id] > self::MAX_DEPTH) { throw new InvalidArgumentException('Course Content may have at most ' . self::MAX_DEPTH . ' levels.'); }
        }
    }

    /**
     * Height of every row's subtree, counting the row itself as 1.
     *
     * @param list<array{id:int,parent_node_id:int|null}> $rows
     * @return array<int,int>
     */
    public function heights(array $rows): array
    {
        [, $children] = $this->index($rows);
        $heights = [];
        foreach ($rows as $row) { $heights[$row['id']] = $this->height($children, $row['id']); }
        return $heights;
    }

    /**
     * @param list<array{id:int,parent_node_id:int|null}> $rows
     * @return array{0:array<int,array{id:int,parent_node_id:int|null}>,1:array<int,list<int>>}
     */
    private function index(array $rows): array
    {
        $byId = []; $children = [0 => []];
        foreach ($rows as $row) {
            $byId[$row['id']] = $row;
            $children[$row['parent_node_id'] ?? 0][] = $row['id'];
        }
        return [$byId, $children];
    }

    /**
     * @param list<array{id:int,parent_node_id:int|null}> $rows
     * @param array<int,array{id:int,parent_node_id:int|null}> $byId
     * @param list<int> $nodeIds
     * @return non-empty-list<int>
     */
    private function outermost(array $rows, array $byId, array $nodeIds): array
    {
        $selected = [];
        foreach ($nodeIds as $id) {
            if (!isset($byId[$id])) { throw new InvalidArgumentException('A selected Course Content row is no longer in this course.'); }
            $selected[$id] = true;
        }
        $moving = [];
        foreach ($rows as $row) {
            if (!isset($selected[$row['id']])) { continue; }
            $ancestor = $row['parent_node_id']; $covered = false;
            while ($ancestor !== null) { if (isset($selected[$ancestor])) { $covered = true; break; } $ancestor = $byId[$ancestor]['parent_node_id']; }
            if (!$covered) { $moving[] = $row['id']; }
        }
        if ($moving === []) { throw new InvalidArgumentException('Select one or more Course Content rows.'); }
        return $moving;
    }

    /** @param array<int,array{id:int,parent_node_id:int|null}> $byId */
    private function isWithin(array $byId, int $id, int $ancestor): bool
    {
        for ($current = $id; $current !== null; $current = $byId[$current]['parent_node_id']) {
            if ($current === $ancestor) { return true; }
        }
        return false;
    }

    /** @param array<int,array{id:int,parent_node_id:int|null}> $byId */
    private function depth(array $byId, ?int $id): int
    {
        $depth = 0;
        for ($current = $id; $current !== null; $current = $byId[$current]['parent_node_id']) { $depth++; }
        return $depth;
    }

    /** @param array<int,list<int>> $children */
    private function height(array $children, int $id): int
    {
        $height = 1;
        foreach ($children[$id] ?? [] as $child) { $height = max($height, 1 + $this->height($children, $child)); }
        return $height;
    }

    /**
     * @param array<int,array{id:int,parent_node_id:int|null}> $byId
     * @param array<int,list<int>> $children
     * @return list<array{id:int,parent_node_id:int|null}>
     */
    private function walk(array $byId, array $children): array
    {
        $result = [];
        $visit = function (int $parent) use (&$visit, &$result, $byId, $children): void {
            foreach ($children[$parent] ?? [] as $id) { $result[] = $byId[$id]; $visit($id); }
        };
        $visit(0);
        return $result;
    }
}

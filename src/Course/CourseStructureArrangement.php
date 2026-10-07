<?php

declare(strict_types=1);

namespace CattoLearning\Course;

use CattoLearning\Support\TreeArrangement;
use CattoLearning\Support\TreeMoveRefused;
use InvalidArgumentException;

/**
 * Ordered Course Content hierarchy operations. Rows are `{id, parent_node_id}` in tree order: each
 * row is followed by its descendants. Every operation returns a new, valid tree or throws; nothing
 * here touches the database, so CourseItemService validates a move in full before it persists it.
 *
 * The rules themselves are the shared TreeArrangement (also used by the course categories); this
 * class keeps Course Content's row shape and words a refusal for its readers.
 *
 * Changelog:
 * 2026/10/07 SAST
 * - The tree rules moved to Support\TreeArrangement, shared with the category tree; the messages
 *   and the public API are unchanged.
 */
final class CourseStructureArrangement
{
    public const MAX_DEPTH = 3;
    public const DIRECTIONS = TreeArrangement::DIRECTIONS;

    private const MESSAGES = [
        TreeMoveRefused::MISSING_ROW => 'A selected Course Content row is no longer in this course.',
        TreeMoveRefused::NOTHING_CHOSEN => 'Select one or more Course Content rows.',
        TreeMoveRefused::MISSING_DESTINATION => 'The destination is no longer in this course.',
        TreeMoveRefused::CYCLE => 'A row cannot be moved inside itself or one of its own rows.',
        TreeMoveRefused::TOO_DEEP => 'Course Content may have at most ' . self::MAX_DEPTH . ' levels.',
        TreeMoveRefused::BAD_POSITION => 'Choose a valid position.',
        TreeMoveRefused::BAD_DIRECTION => 'Select a valid Course Content move.',
        TreeMoveRefused::ALREADY_OUTER => 'This row is already at the outer level.',
        TreeMoveRefused::STALE => 'Course Content changed while you were moving it. Reload the page and try again.',
    ];

    private readonly TreeArrangement $tree;

    public function __construct()
    {
        $this->tree = new TreeArrangement(self::MAX_DEPTH);
    }

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
        return $this->worded(fn(): array => self::back($this->tree->place(self::shared($rows), $nodeIds, $parentId, $index)));
    }

    /**
     * Top, up, down and bottom move within the current parent; out places a row immediately after
     * its former parent. Moving a selection treats each outermost selected row in turn.
     *
     * @param list<array{id:int,parent_node_id:int|null}> $rows
     * @param list<int> $nodeIds
     * @return list<array{id:int,parent_node_id:int|null}>
     */
    public function move(array $rows, array $nodeIds, string $direction): array
    {
        return $this->worded(fn(): array => self::back($this->tree->move(self::shared($rows), $nodeIds, $direction)));
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
        return $this->worded(fn(): array => $this->tree->destinations(self::shared($rows), $nodeIds));
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
        $this->worded(function () use ($structure, $rows): array {
            $this->tree->assertComplete(array_map(static fn(array $row): int => (int) $row['id'], $structure), self::shared($rows));
            return [];
        });
    }

    /**
     * Height of every row's subtree, counting the row itself as 1.
     *
     * @param list<array{id:int,parent_node_id:int|null}> $rows
     * @return array<int,int>
     */
    public function heights(array $rows): array
    {
        return $this->tree->heights(self::shared($rows));
    }

    /**
     * @template T
     * @param callable():T $operation
     * @return T
     */
    private function worded(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (TreeMoveRefused $refused) {
            throw new InvalidArgumentException(self::MESSAGES[$refused->reason] ?? 'Course Content could not be moved.', 0, $refused);
        }
    }

    /**
     * @param list<array{id:int,parent_node_id:int|null}> $rows
     * @return list<array{id:int,parent:int|null}>
     */
    private static function shared(array $rows): array
    {
        return array_map(static fn(array $row): array => ['id' => $row['id'], 'parent' => $row['parent_node_id']], $rows);
    }

    /**
     * @param list<array{id:int,parent:int|null}> $rows
     * @return list<array{id:int,parent_node_id:int|null}>
     */
    private static function back(array $rows): array
    {
        return array_map(static fn(array $row): array => ['id' => $row['id'], 'parent_node_id' => $row['parent']], $rows);
    }
}

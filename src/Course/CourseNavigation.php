<?php

declare(strict_types=1);

namespace CattoLearning\Course;

/**
 * The reading order of a course for Previous and Next: every Course Item placement, and every section
 * that has a page of its own (an introduction or an outline). A section with neither is only a
 * heading in the outline and is not a stop. The learner reader, ADMIN preview, assessment pages and
 * the public preview all use this one rule, so Previous and Next always agree.
 */
final class CourseNavigation
{
    /**
     * @param list<array<string,mixed>> $structure Rows in tree order.
     * @return list<array<string,mixed>>
     */
    public static function sequence(array $structure): array
    {
        return array_values(array_filter($structure, static fn(array $row): bool => self::isStop($row)));
    }

    /** @param array<string,mixed> $row */
    public static function isStop(array $row): bool
    {
        if (($row['node_type'] ?? '') === 'item') { return true; }
        return ($row['node_type'] ?? '') === 'section' && (trim((string) ($row['section_introduction_html'] ?? '')) !== '' || !empty($row['show_outline']));
    }

    /**
     * The row of one part, or null when the structure does not hold it.
     *
     * @param list<array<string,mixed>> $structure
     * @return array<string,mixed>|null
     */
    public static function row(array $structure, int $nodeId): ?array
    {
        foreach ($structure as $row) { if ((int) ($row['id'] ?? 0) === $nodeId) { return $row; } }
        return null;
    }

    /**
     * The stops before and after a row, or null at either end or when the row is not a stop.
     *
     * @param list<array<string,mixed>> $structure
     * @return array{previous:array<string,mixed>|null,next:array<string,mixed>|null}
     */
    public static function neighbours(array $structure, int $nodeId): array
    {
        $sequence = self::sequence($structure);
        $index = array_search($nodeId, array_map(static fn(array $row): int => (int) $row['id'], $sequence), true);
        if ($index === false) { return ['previous' => null, 'next' => null]; }
        return ['previous' => $sequence[$index - 1] ?? null, 'next' => $sequence[$index + 1] ?? null];
    }

    /**
     * The outline with each done part, and each section whose every part is done, marked is_done.
     *
     * @param list<array<string,mixed>> $structure
     * @param array<int,true> $done
     * @return list<array<string,mixed>>
     */
    public static function withDone(array $structure, array $done): array
    {
        $parts = [];
        foreach ($structure as $row) {
            if ((string) $row['node_type'] === 'item') { $parts[] = $row; }
        }
        $parentOf = [];
        foreach ($structure as $row) { $parentOf[(int) $row['id']] = $row['parent_node_id'] === null ? null : (int) $row['parent_node_id']; }
        $sections = [];
        foreach ($parts as $part) {
            for ($parent = $parentOf[(int) $part['id']] ?? null; $parent !== null; $parent = $parentOf[$parent] ?? null) {
                $sections[$parent]['all'] = ($sections[$parent]['all'] ?? 0) + 1;
                $sections[$parent]['done'] = ($sections[$parent]['done'] ?? 0) + (isset($done[(int) $part['id']]) ? 1 : 0);
            }
        }
        foreach ($structure as &$row) {
            $count = $sections[(int) $row['id']] ?? null;
            $row['is_done'] = (string) $row['node_type'] === 'item' ? isset($done[(int) $row['id']]) : ($count !== null && $count['done'] === $count['all']);
        }
        unset($row);
        return $structure;
    }

    /**
     * A visitor's progress through a public preview (owner, 2026-11-01): every part in the outline they are shown, and
     * how many of them are done. A learner's progress is counted by CourseItemRepository::partsProgressJoin().
     *
     * @param list<array<string,mixed>> $structure
     * @param array<int,true> $done
     * @return array{total:int,done:int,percentage:int}
     */
    public static function progress(array $structure, array $done): array
    {
        $total = 0; $count = 0;
        foreach ($structure as $row) {
            if ((string) ($row['node_type'] ?? '') !== 'item') { continue; }
            $total++;
            if (isset($done[(int) $row['id']])) { $count++; }
        }
        return ['total' => $total, 'done' => $count, 'percentage' => $total > 0 ? (int) round($count / $total * 100) : 0];
    }

    /**
     * The outline as the sidebar draws it (owner, 2026-11-02: "only the current section open ... as you progress through
     * the course, the sections open and close"): each row with its children, and is_open on the sections on the way to
     * the current stop. A row whose section is not shown, as in the public preview, stands at the top level.
     *
     * @param list<array<string,mixed>> $structure Rows in tree order.
     * @return list<array<string,mixed>>
     */
    public static function outline(array $structure, int|string|null $currentNodeId = null): array
    {
        $parentOf = [];
        foreach ($structure as $row) { $parentOf[(int) $row['id']] = ($row['parent_node_id'] ?? null) === null ? null : (int) $row['parent_node_id']; }
        $open = [];
        for ($id = (int) $currentNodeId; $id > 0 && array_key_exists($id, $parentOf); $id = (int) $parentOf[$id]) { $open[$id] = true; }
        $children = [];
        foreach ($structure as $row) {
            $parent = $parentOf[(int) $row['id']];
            $children[$parent !== null && array_key_exists($parent, $parentOf) ? $parent : 0][] = $row;
        }
        return self::outlineBranch($children, 0, $open);
    }

    /**
     * @param array<int,list<array<string,mixed>>> $children
     * @param array<int,true> $open
     * @return list<array<string,mixed>>
     */
    private static function outlineBranch(array $children, int $parent, array $open): array
    {
        $rows = [];
        foreach ($children[$parent] ?? [] as $row) {
            $id = (int) $row['id'];
            $row['children'] = self::outlineBranch($children, $id, $open);
            $row['is_open'] = isset($open[$id]) && $row['children'] !== [];
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * The rows a tick on a part changes: the part's own and those of the sections holding it, as far as the outline shows them.
     *
     * @param list<array<string,mixed>> $structure
     * @return list<array<string,mixed>>
     */
    public static function rowsUpTo(array $structure, int $nodeId): array
    {
        $byId = [];
        foreach ($structure as $row) { $byId[(int) $row['id']] = $row; }
        $rows = [];
        for ($row = $byId[$nodeId] ?? null; $row !== null; $row = ($row['parent_node_id'] ?? null) === null ? null : ($byId[(int) $row['parent_node_id']] ?? null)) { $rows[] = $row; }
        return $rows;
    }
}

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
}

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
}

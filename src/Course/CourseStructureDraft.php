<?php

declare(strict_types=1);

namespace CattoLearning\Course;

use InvalidArgumentException;

/** Provisional, ordered Course Content hierarchy. No method in this class writes to the database. */
final class CourseStructureDraft
{
    /** @param list<array<string,mixed>> $structure
     * @return list<array{id:int,parent_node_id:int|null}> */
    public function fromStructure(array $structure): array
    {
        return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'parent_node_id' => $row['parent_node_id'] === null ? null : (int) $row['parent_node_id']], $structure);
    }

    /** @param list<array{id:int,parent_node_id:int|null}> $draft
     * @return list<array{id:int,parent_node_id:int|null}> */
    public function move(array $draft, int $nodeId, string $direction): array
    {
        if (!in_array($direction, ['up', 'down', 'indent', 'unindent'], true)) { throw new InvalidArgumentException('Select a valid Course Content move.'); }
        $byId = []; $children = [];
        foreach ($draft as $row) {
            $id = $row['id']; $byId[$id] = $row;
            $children[$row['parent_node_id'] ?? 0][] = $id;
        }
        if (!isset($byId[$nodeId])) { throw new InvalidArgumentException('The Course Content row no longer exists.'); }
        $parent = $byId[$nodeId]['parent_node_id']; $parentKey = $parent ?? 0;
        $siblings = $children[$parentKey]; $index = array_search($nodeId, $siblings, true);
        if ($index === false) { throw new InvalidArgumentException('The Course Content order is invalid.'); }
        if ($direction === 'up' || $direction === 'down') {
            $other = $index + ($direction === 'up' ? -1 : 1);
            if (!isset($siblings[$other])) { return $draft; }
            [$siblings[$index], $siblings[$other]] = [$siblings[$other], $siblings[$index]];
            $children[$parentKey] = $siblings;
        } elseif ($direction === 'indent') {
            if ($index === 0) { throw new InvalidArgumentException('Indent requires a preceding Course Item or section.'); }
            $newParent = $siblings[$index - 1];
            if ($this->depth($byId, $newParent) >= 3 || $this->subtreeHeight($children, $nodeId) + $this->depth($byId, $newParent) > 3) { throw new InvalidArgumentException('Course Content may have at most three levels.'); }
            array_splice($children[$parentKey], $index, 1);
            $children[$newParent][] = $nodeId;
            $byId[$nodeId]['parent_node_id'] = $newParent;
        } else {
            if ($parent === null) { throw new InvalidArgumentException('This row is already at the outer level.'); }
            $grandparent = $byId[$parent]['parent_node_id']; $grandparentKey = $grandparent ?? 0;
            array_splice($children[$parentKey], $index, 1);
            $parentIndex = array_search($parent, $children[$grandparentKey], true);
            if ($parentIndex === false) { throw new InvalidArgumentException('The Course Content parent is invalid.'); }
            array_splice($children[$grandparentKey], $parentIndex + 1, 0, [$nodeId]);
            $byId[$nodeId]['parent_node_id'] = $grandparent;
        }
        $result = [];
        $walk = function (int $parentId) use (&$walk, &$result, $children, $byId): void {
            foreach ($children[$parentId] ?? [] as $id) { $result[] = $byId[$id]; $walk($id); }
        };
        $walk(0);
        return $result;
    }

    /** @param list<array{id:int,parent_node_id:int|null}> $draft
     * @param list<int> $nodeIds
     * @return list<array{id:int,parent_node_id:int|null}> */
    public function moveSelection(array $draft, array $nodeIds, string $direction): array
    {
        $selected = array_fill_keys($nodeIds, true); $parents = []; $ordered = [];
        foreach ($draft as $row) { $parents[$row['id']] = $row['parent_node_id']; }
        foreach ($draft as $row) {
            $id = $row['id'];
            if (!isset($selected[$id])) { continue; }
            $parent = $parents[$id]; $ancestorSelected = false;
            while ($parent !== null) { if (isset($selected[$parent])) { $ancestorSelected = true; break; } $parent = $parents[$parent] ?? null; }
            if (!$ancestorSelected) { $ordered[] = $id; }
        }
        if ($ordered === []) { throw new InvalidArgumentException('Select one or more Course Content rows.'); }
        if ($direction === 'down') { $ordered = array_reverse($ordered); }
        foreach ($ordered as $id) { $draft = $this->move($draft, $id, $direction); }
        return $draft;
    }

    /** @param list<array<string,mixed>> $structure
     * @param list<array{id:int,parent_node_id:int|null}> $draft
     * @return list<array<string,mixed>> */
    public function preview(array $structure, array $draft): array
    {
        $source = []; foreach ($structure as $row) { $source[(int) $row['id']] = $row; }
        if (count($source) !== count($draft)) { throw new InvalidArgumentException('Course Content changed while you were editing. Cancel and reopen the editor.'); }
        $result = []; $depths = []; $siblings = [];
        foreach ($draft as $entry) {
            $id = $entry['id']; $parent = $entry['parent_node_id'];
            if (!isset($source[$id]) || isset($depths[$id]) || ($parent !== null && !isset($depths[$parent]))) { throw new InvalidArgumentException('Course Content changed while you were editing. Cancel and reopen the editor.'); }
            $depth = $parent === null ? 1 : $depths[$parent] + 1;
            if ($depth > 3) { throw new InvalidArgumentException('Course Content may have at most three levels.'); }
            $depths[$id] = $depth;
            $row = $source[$id]; $row['parent_node_id'] = $parent; $row['depth'] = $depth;
            $result[] = $row; $siblings[$parent ?? 0][] = $id;
        }
        foreach ($result as &$row) {
            $id = (int) $row['id']; $parent = $row['parent_node_id']; $group = $siblings[$parent ?? 0]; $index = array_search($id, $group, true);
            $row['can_up'] = $index > 0; $row['can_down'] = isset($group[$index + 1]);
            $previous = $index > 0 ? $group[$index - 1] : null;
            $row['can_indent'] = $previous !== null && $depths[$previous] + $this->subtreeHeightFromDraft($draft, $id) <= 3;
            $row['can_unindent'] = $parent !== null;
            $row['has_children'] = isset($siblings[$id]);
        }
        unset($row);
        return $result;
    }

    /** @param array<int,array{id:int,parent_node_id:int|null}> $byId */
    private function depth(array $byId, int $id): int
    {
        $depth = 0;
        while ($id !== 0) { $depth++; $id = $byId[$id]['parent_node_id'] ?? 0; }
        return $depth;
    }

    /** @param array<int,list<int>> $children */
    private function subtreeHeight(array $children, int $id): int
    {
        $height = 1;
        foreach ($children[$id] ?? [] as $child) { $height = max($height, 1 + $this->subtreeHeight($children, $child)); }
        return $height;
    }

    /** @param list<array{id:int,parent_node_id:int|null}> $draft */
    private function subtreeHeightFromDraft(array $draft, int $id): int
    {
        $children = []; foreach ($draft as $entry) { $children[$entry['parent_node_id'] ?? 0][] = $entry['id']; }
        return $this->subtreeHeight($children, $id);
    }
}

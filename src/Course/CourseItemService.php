<?php

declare(strict_types=1);

namespace CattoLearning\Course;

use CattoLearning\Infrastructure\Persistence\AuditRepository;
use CattoLearning\Infrastructure\Persistence\TransactionManager;
use InvalidArgumentException;

/** Owns Course Item lifecycle, placements, structure, availability and publication validation. */
final class CourseItemService
{
    public const TYPES = ['html_lesson','assessment','diagnostic','pdf','image_graphic','uploaded_video','youtube','audio','markdown','document'];
    private const RESOURCE_TYPES = ['pdf','image_graphic','uploaded_video','markdown','document'];

    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly CourseItemRepository $items,
        private readonly CourseRepository $courses,
        private readonly CourseHtml $html,
        private readonly AuditRepository $audit,
        private readonly ResourceLibraryService $resources
    ) {
    }

    /** @return array<string,mixed> */
    public function item(int $id): array
    {
        $item = $this->items->item($id);
        if ($item === null) { throw new InvalidArgumentException('The Course Item does not exist.'); }
        $item['usage'] = $this->items->usage($id, (string) $item['item_key']);
        $item['course_usage'] = $this->items->courseUsage($id);
        $item['questions'] = in_array((string) $item['item_type'], ['assessment','diagnostic'], true) ? $this->items->questions($id, true) : [];
        return $item;
    }

    /** @return array<string,mixed> */
    public function library(string $search = '', string $type = ''): array
    {
        if ($type !== '' && !in_array($type, self::TYPES, true)) { throw new InvalidArgumentException('Select a valid Course Item type.'); }
        $items = $this->items->items(trim($search), $type);
        $byId = [];
        foreach ($items as $item) { $byId[(int) $item['id']] = $item + ['usage' => []]; }
        $groups = [];
        foreach ($this->items->libraryPlacements() as $placement) {
            $id = (int) $placement['course_item_id'];
            if (!isset($byId[$id])) { continue; }
            $courseId = (int) $placement['course_id'];
            if (!isset($groups[$courseId])) { $groups[$courseId] = ['course' => ['id' => $courseId, 'title' => $placement['course_title']], 'items' => []]; }
            $groups[$courseId]['items'][] = $byId[$id];
        }
        $groups = array_values($groups);
        $used = [];
        foreach ($groups as $group) { foreach ($group['items'] as $item) { $used[(int) $item['id']] = true; } }
        return ['groups' => $groups, 'unused' => array_values(array_filter($items, static fn(array $item): bool => !isset($used[(int) $item['id']]))), 'search' => trim($search), 'type' => $type];
    }

    /**
     * Rebuilds the explicit unsaved editor form for native POST controls.
     * @param array<string,mixed> $input
     * @param array<string,mixed> $before
     * @return array<string,mixed>
     */
    public function editorDraft(array $input, array $before): array
    {
        $questions = [];
        foreach ((array) ($input['question_html'] ?? []) as $index => $html) {
            $options = [];
            foreach ((array) ($input['option_html'][$index] ?? []) as $oi => $option) {
                $options[] = ['id' => (int) ($input['option_id'][$index][$oi] ?? 0), 'option_html' => (string) $option, 'is_correct' => isset($input['correct_option'][$index]) && (string) $input['correct_option'][$index] === (string) $oi];
            }
            $questions[] = ['id' => (int) ($input['question_id'][$index] ?? 0), 'question_html' => (string) $html, 'points' => $input['question_points'][$index] ?? 1, 'explanation_html' => $input['question_explanation'][$index] ?? '', 'difficulty' => $input['question_difficulty'][$index] ?? 'standard', 'incorrect_points' => $input['incorrect_points'][$index] ?? 0, 'practice_eligible' => isset($input['practice_eligible'][$index]), 'graded_eligible' => isset($input['graded_eligible'][$index]), 'remediation_item_keys' => preg_split('/[,\s]+/', trim((string) ($input['remediation_item_keys'][$index] ?? ''))) ?: [], 'options' => $options];
        }
        $action = explode(':', (string) ($input['question_action'] ?? ''));
        $index = (int) ($action[1] ?? -1); $optionIndex = (int) ($action[2] ?? -1);
        if ($action[0] === 'add-question') { $questions[] = []; }
        elseif ($action[0] === 'remove-question' && isset($questions[$index])) { array_splice($questions, $index, 1); }
        elseif ($action[0] === 'add-option' && isset($questions[$index])) { $questions[$index]['options'][] = []; }
        elseif ($action[0] === 'remove-option' && isset($questions[$index]['options'][$optionIndex]) && count($questions[$index]['options']) > 2) { array_splice($questions[$index]['options'], $optionIndex, 1); }
        $draft = array_replace($before, $input);
        foreach (['practice','practice_enabled','randomise_questions','randomise_options','negative_marking'] as $field) { $draft[$field] = !empty($input[$field]); }
        foreach (['youtube_id','remote_uri','caption','transcript','poster_resource_id','subtitle_resource_id'] as $field) { if (array_key_exists($field, $input)) { $draft['type_config'][$field] = $input[$field]; } }
        $draft['questions'] = $questions;
        return $draft;
    }

    /** @param array<string,mixed> $input */
    public function create(array $input, int $userId): int
    {
        $data = $this->validateItem($input);
        $id = $this->transactions->run(function () use ($data, $input, $userId): int {
            $id = $this->items->createItem($data, $userId);
            $this->saveTypeDetails($id, $data['item_type'], $input);
            $this->items->replaceReferences($id, $data['item_type'] === 'html_lesson' ? $this->shortcodes($data['content_source']) : []);
            return $id;
        });
        $this->audit->record($userId, 'course_item.created', ['course_item_id' => $id, 'item_key' => $data['item_key']]);
        return $id;
    }

    /** @param array<string,mixed> $input */
    public function update(int $id, array $input, int $userId): void
    {
        $before = $this->item($id); $data = $this->validateItem($input + $before, $id);
        $this->transactions->run(function () use ($id, $data, $input, $userId): void {
            $this->items->updateItem($id, $data, $userId);
            $this->saveTypeDetails($id, $data['item_type'], $input);
            $this->items->replaceReferences($id, $data['item_type'] === 'html_lesson' ? $this->shortcodes($data['content_source']) : []);
        });
        $this->audit->record($userId, 'course_item.updated', ['course_item_id' => $id, 'old_key' => $before['item_key'], 'item_key' => $data['item_key']]);
    }

    /** @param array<string,mixed> $input */
    public function saveAs(int $sourceId, array $input, int $userId): int
    {
        $source = $this->item($sourceId);
        $input += $source;
        if (isset($input['question_html'])) { unset($input['questions']); }
        $input['item_key'] = (string) ($input['item_key'] ?? '');
        $input['title'] = (string) ($input['title'] ?? '');
        if (isset($input['questions']) && is_array($input['questions'])) {
            foreach ($input['questions'] as &$question) {
                unset($question['id']);
                foreach ($question['options'] as &$option) { unset($option['id']); }
                unset($option);
            }
            unset($question);
        }
        unset($input['question_id'], $input['option_id']);
        $id = $this->create($input, $userId);
        $this->audit->record($userId, 'course_item.saved_as', ['source_course_item_id' => $sourceId, 'course_item_id' => $id]);
        return $id;
    }

    public function delete(int $id, int $userId): void
    {
        $item = $this->item($id);
        if ($item['usage'] !== []) { throw new InvalidArgumentException('Remove every placement and embedded reference before deleting this Course Item.'); }
        $this->items->deleteItem($id);
        $this->audit->record($userId, 'course_item.deleted', ['course_item_id' => $id, 'item_key' => $item['item_key']]);
    }

    public function canAccessResource(int $resourceId, ?int $userId, bool $administrator): bool
    {
        if ($administrator) { return true; }
        foreach ($this->items->resourceContexts($resourceId) as $context) {
            if ((bool) $context['public_preview'] && $context['status'] === 'published') { return true; }
            if ($userId === null) { continue; }
            $courseId = (int) $context['course_id'];
            if ($this->courses->userCanManageCourse($courseId, $userId)) { return true; }
            $enrolment = $this->courses->enrolment($userId, $courseId);
            if ($enrolment === null || !in_array($enrolment['status'], ['active','completed'], true) || empty($enrolment['started_at']) || !empty($enrolment['access_removed_at'])) { continue; }
            if (!empty($enrolment['expires_at']) && strtotime((string) $enrolment['expires_at']) <= time()) { continue; }
            foreach ($this->availability($courseId, (string) $enrolment['started_at'], false) as $node) {
                if ((int) $node['id'] === (int) $context['node_id'] && !$node['is_locked']) { return true; }
            }
        }
        return false;
    }

    /** @return array<string,mixed> */
    public function courseContent(int $courseId): array
    {
        $course = $this->courses->findById($courseId);
        if ($course === null) { throw new InvalidArgumentException('The course does not exist.'); }
        $course['structure'] = $this->availability($courseId, null, false);
        $siblings = [];
        foreach ($course['structure'] as $row) { $siblings[(int) ($row['parent_node_id'] ?? 0)][] = $row; }
        $sectionDepth = [];
        foreach (array_reverse($course['structure']) as $row) {
            $id = (int) $row['id'];
            $sectionDepth[$id] = max($sectionDepth[$id] ?? 0, $row['node_type'] === 'section' ? (int) $row['depth'] : 0);
            if ($row['parent_node_id'] !== null) { $parent = (int) $row['parent_node_id']; $sectionDepth[$parent] = max($sectionDepth[$parent] ?? 0, $sectionDepth[$id]); }
        }
        foreach ($course['structure'] as &$row) {
            $group = $siblings[(int) ($row['parent_node_id'] ?? 0)];
            $index = array_search($row['id'], array_column($group, 'id'), true);
            $previous = $index !== false && $index > 0 ? $group[$index - 1] : null;
            $row['can_up'] = $index !== false && $index > 0;
            $row['can_down'] = $index !== false && isset($group[$index + 1]);
            $row['can_indent'] = $previous !== null && (int) $previous['depth'] < 3;
            $row['can_unindent'] = $row['parent_node_id'] !== null;
        }
        unset($row);
        $course['publication_validation'] = $this->publicationValidation($courseId);
        return $course;
    }

    /** @param array<string,mixed> $input */
    public function createAttached(int $courseId, array $input, int $userId): int
    {
        return $this->transactions->run(function () use ($courseId, $input, $userId): int {
            $itemId = $this->create($input, $userId);
            $this->addExisting($courseId, $itemId, $input, $userId);
            return $itemId;
        });
    }

    /** @param array<string,mixed> $input */
    public function addExisting(int $courseId, int $itemId, array $input, int $userId): int
    {
        $item = $this->item($itemId);
        $parent = $this->nullableId($input['parent_node_id'] ?? null);
        $data = $this->placementData($courseId, $item, $input);
        $position = $this->items->nextPosition($courseId, $parent);
        $node = $this->items->createPlacement($courseId, $itemId, $parent, $position, $data);
        $this->audit->record($userId, 'course_item.placed', ['course_id' => $courseId, 'course_item_id' => $itemId, 'node_id' => $node]);
        return $node;
    }

    /** @param array<string,mixed> $input */
    public function addSection(int $courseId, array $input, int $userId): int
    {
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') { throw new InvalidArgumentException('A section needs a title.'); }
        $parent = $this->nullableId($input['parent_node_id'] ?? null);
        $delay = $this->delay($input);
        $id = $this->items->createSection($courseId, $parent, $this->items->nextPosition($courseId, $parent), $title, $this->html->preserve((string) ($input['introduction_html'] ?? '')), !empty($input['show_outline']), $delay);
        $this->audit->record($userId, 'course_section.created', ['course_id' => $courseId, 'node_id' => $id]);
        return $id;
    }

    public function removeFromCourse(int $courseId, int $nodeId, int $userId): void
    {
        $node = $this->items->node($courseId, $nodeId);
        if ($node === null || (string) $node['node_type'] !== 'item') { throw new InvalidArgumentException('The Course Item placement does not exist.'); }
        $this->items->removeNode($courseId, $nodeId);
        $this->audit->record($userId, 'course_item.removed', ['course_id' => $courseId, 'node_id' => $nodeId]);
    }

    /** @param array<string,mixed> $input */
    public function updatePlacement(int $courseId, int $nodeId, array $input, int $userId): void
    {
        $placement = $this->items->placement($courseId, $nodeId);
        if ($placement === null) { throw new InvalidArgumentException('The Course Item placement does not exist.'); }
        $item = $this->item((int) $placement['course_item_id']);
        $hasDelayInput = array_intersect(['relative_delay_minutes','delay_weeks','delay_days','delay_hours','delay_minutes'], array_keys($input)) !== [];
        if (!$hasDelayInput) { $input['relative_delay_minutes'] = (int) $placement['relative_delay_minutes']; }
        $this->items->updatePlacement($nodeId, $this->placementData($courseId, $item, $input));
        $this->audit->record($userId, 'course_item.placement_updated', ['course_id' => $courseId, 'course_item_id' => (int) $placement['course_item_id'], 'node_id' => $nodeId]);
    }

    public function move(int $courseId, int $nodeId, string $direction, int $userId): void
    {
        $node = $this->items->node($courseId, $nodeId);
        if ($node === null || !in_array($direction, ['up','down','indent','unindent'], true)) { throw new InvalidArgumentException('Select a valid Course Content move.'); }
        $parent = $node['parent_node_id'] === null ? null : (int) $node['parent_node_id'];
        $siblings = $this->items->siblings($courseId, $parent);
        $index = array_search($nodeId, array_map(static fn(array $row): int => (int) $row['id'], $siblings), true);
        if ($index === false) { return; }
        if ($direction === 'up' || $direction === 'down') {
            $target = $index + ($direction === 'up' ? -1 : 1);
            if (isset($siblings[$target])) { $this->transactions->run(fn() => $this->items->swapPositions($courseId, $nodeId, (int) $siblings[$target]['id'])); }
            return;
        }
        if ($direction === 'indent') {
            $previous = $siblings[$index - 1] ?? null;
            $previousDepth = 0;
            foreach ($this->items->structure($courseId) as $row) { if ($previous !== null && (int) $row['id'] === (int) $previous['id']) { $previousDepth = (int) $row['depth']; break; } }
            if ($previous === null || $previousDepth >= 3) { throw new InvalidArgumentException('Indent requires a preceding Course Item or section below the three-level limit.'); }
            $newParent = (int) $previous['id'];
        } else {
            if ($parent === null) { throw new InvalidArgumentException('This row is already at the outer level.'); }
            $parentNode = $this->items->node($courseId, $parent);
            $newParent = $parentNode['parent_node_id'] === null ? null : (int) $parentNode['parent_node_id'];
        }
        $structure = $this->items->structure($courseId);
        $nodeDepth = 1; $parentDepth = 0; $subtreeDepth = 0; $inside = false;
        foreach ($structure as $row) {
            if ((int) $row['id'] === $newParent) { $parentDepth = (int) $row['depth']; }
            if ((int) $row['id'] === $nodeId) { $nodeDepth = (int) $row['depth']; $inside = true; }
            elseif ($inside && (int) $row['depth'] <= $nodeDepth) { $inside = false; }
            if ($inside && $row['node_type'] === 'section') { $subtreeDepth = max($subtreeDepth, (int) $row['depth'] - $nodeDepth + 1); }
        }
        if ($parentDepth + $subtreeDepth > 3) { throw new InvalidArgumentException('Moving this Course Content subtree would exceed three levels.'); }
        $position = $this->items->nextPosition($courseId, $newParent);
        $this->items->moveNode($courseId, $nodeId, $newParent, $position);
        $this->audit->record($userId, 'course_content.moved', ['course_id' => $courseId, 'node_id' => $nodeId, 'direction' => $direction]);
    }

    /** @param list<int> $nodeIds */
    public function moveSelection(int $courseId, array $nodeIds, string $direction, int $userId): void
    {
        $selected = array_fill_keys($nodeIds, true); $ordered = []; $selectedAncestors = [];
        foreach ($this->items->structure($courseId) as $row) {
            $id = (int) $row['id']; $parent = (int) ($row['parent_node_id'] ?? 0);
            $nested = isset($selectedAncestors[$parent]);
            if ($nested || isset($selected[$id])) { $selectedAncestors[$id] = true; }
            if (isset($selected[$id]) && !$nested) { $ordered[] = $id; }
        }
        if ($ordered === []) { throw new InvalidArgumentException('Select one or more Course Content rows.'); }
        if ($direction === 'down') { $ordered = array_reverse($ordered); }
        $this->transactions->run(function () use ($courseId, $ordered, $direction, $userId): void {
            foreach ($ordered as $id) {
                if (in_array($direction, ['up','down'], true)) {
                    $node = $this->items->node($courseId, $id);
                    $siblings = $this->items->siblings($courseId, $node['parent_node_id'] === null ? null : (int) $node['parent_node_id']);
                    $index = array_search($id, array_map(static fn(array $row): int => (int) $row['id'], $siblings), true);
                    $next = $index === false ? null : ($siblings[$index + ($direction === 'up' ? -1 : 1)] ?? null);
                    if ($next === null || in_array((int) $next['id'], $ordered, true)) { continue; }
                }
                $this->move($courseId, $id, $direction, $userId);
            }
        });
    }

    /** @param array<string,mixed> $input */
    public function updateSection(int $courseId, int $nodeId, array $input, int $userId): void
    {
        $node = $this->items->node($courseId, $nodeId);
        if ($node === null || $node['node_type'] !== 'section') { throw new InvalidArgumentException('The section does not exist.'); }
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') { throw new InvalidArgumentException('A section needs a title.'); }
        $hasDelay = array_intersect(['relative_delay_minutes','delay_weeks','delay_days','delay_hours','delay_minutes'], array_keys($input)) !== [];
        $delay = $hasDelay ? $this->delay($input) : (int) $node['relative_delay_minutes'];
        $this->transactions->run(fn() => $this->items->updateSection($nodeId, $title, $this->html->preserve((string) ($input['introduction_html'] ?? '')), !empty($input['show_outline']), $delay));
        $this->audit->record($userId, 'course_section.updated', ['course_id' => $courseId, 'node_id' => $nodeId]);
    }

    /** @return array{errors:list<string>,warnings:list<string>} */
    public function publicationValidation(int $courseId): array
    {
        $course = $this->courses->findById($courseId);
        if ($course === null) { throw new InvalidArgumentException('The course does not exist.'); }
        $rows = $this->availability($courseId, null, false);
        $errors = []; $warnings = []; $graded = count($this->items->assessmentContexts($courseId)); $finals = 0;
        $durationMinutes = (int) floor((int) $course['default_access_period_seconds'] / 60);
        foreach ($rows as $row) {
            if ((string) $row['node_type'] !== 'item') { continue; }
            $role = (string) ($row['assessment_role'] ?? 'content');
            if ($role !== 'content' && $row['item_type'] !== 'assessment') { $errors[] = 'Only Assessment Course Items may have a graded or final placement.'; }
            if ($role === 'final' && !empty($row['practice'])) { $errors[] = 'The final assessment cannot be a practice item.'; }
            if ($role === 'final') { $finals++; if ((int) $row['relative_delay_minutes'] !== 0) { $errors[] = 'The final assessment cannot have a time delay.'; } }
            if (in_array((string) $row['item_type'], self::RESOURCE_TYPES, true) && empty($row['resource_id'])) { $errors[] = 'Resource-backed Course Item “' . $row['item_title'] . '” has no Resource.'; }
            if (!(bool) ($row['public_preview'] ?? false)) {
                $unlock = (int) $row['unlock_minutes'];
                if ($unlock >= $durationMinutes) { $errors[] = '“' . $this->title($row) . '” unlocks at or after course expiry.'; }
                elseif ($durationMinutes - $unlock < 10080) { $warnings[] = '“' . $this->title($row) . '” leaves less than seven days before course expiry.'; }
            }
            $this->validateReferences((string) $row['item_key'], [], $errors);
        }
        if ($graded > 0 && $finals !== 1) { $errors[] = 'An assessed course must contain exactly one final Assessment placement.'; }
        if ($finals > 1) { $errors[] = 'A course may contain only one final Assessment placement.'; }
        return ['errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * @param list<string> $path
     * @param list<string> $errors
     */
    private function validateReferences(string $key, array $path, array &$errors): void
    {
        if (in_array($key, $path, true)) { $errors[] = 'Circular Course Item reference: ' . implode(' → ', [...$path, $key]); return; }
        $item = $this->items->itemByKey($key);
        if ($item === null) { $errors[] = 'Unresolved shortcode [course-item:' . $key . '].'; return; }
        if ($item['item_type'] === 'youtube' && empty($item['type_config']['youtube_id'])) { $errors[] = 'YouTube Course Item “' . $item['title'] . '” needs a YouTube ID.'; }
        if ($item['item_type'] === 'audio' && empty($item['resource_id']) && empty($item['type_config']['remote_uri'])) { $errors[] = 'Audio Course Item “' . $item['title'] . '” needs a Resource or remote URI.'; }
        $resourceIds = array_filter([(int) ($item['resource_id'] ?? 0), (int) ($item['type_config']['poster_resource_id'] ?? 0), (int) ($item['type_config']['subtitle_resource_id'] ?? 0)]);
        if (in_array($item['item_type'], self::RESOURCE_TYPES, true) && empty($item['resource_id'])) { $errors[] = 'Course Item “' . $item['title'] . '” needs a Resource.'; }
        foreach ($resourceIds as $resourceId) {
            $resource = $this->items->resource($resourceId);
            if ($resource === null || !is_file($this->resources->path($resource))) { $errors[] = 'Course Item “' . $item['title'] . '” references a missing Resource file.'; }
        }
        if (in_array($item['item_type'], ['assessment','diagnostic'], true)) {
            $questions = $this->items->questions((int) $item['id'], true);
            if ($questions === []) { $errors[] = 'Assessment/Diagnostic “' . $item['title'] . '” has no questions.'; }
            $gradedPool = count(array_filter($questions, static fn(array $question): bool => (bool) $question['graded_eligible']));
            if ($item['item_type'] === 'assessment' && empty($item['practice']) && $gradedPool < (int) $item['graded_question_count']) { $errors[] = '“' . $item['title'] . '” has fewer eligible graded questions than configured.'; }
            foreach ($questions as $question) {
                if (count($question['options']) < 2 || count(array_filter($question['options'], static fn(array $option): bool => (bool) $option['is_correct'])) !== 1) { $errors[] = '“' . $item['title'] . '” has an invalid question answer bank.'; }
            }
        }
        if ($item['item_type'] !== 'html_lesson') { return; }
        preg_match_all('/\[course-item:([^\]\r\n]*)\]/', (string) $item['content_source'], $allReferences);
        foreach ($allReferences[1] as $reference) { if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,119}$/', $reference) !== 1) { $errors[] = 'Invalid Course Item shortcode key: ' . $reference; } }
        foreach ($this->shortcodes((string) $item['content_source']) as $reference) { $this->validateReferences($reference, [...$path, $key], $errors); }
    }

    /** @return list<array<string,mixed>> */
    public function availability(int $courseId, ?string $startedAt, bool $publicOnly): array
    {
        $rows = $this->items->structure($courseId, $publicOnly);
        if ($publicOnly) { $rows = array_values(array_filter($rows, static fn(array $row): bool => $row['node_type'] === 'item')); }
        $availableByNode = []; $lastByParent = []; $scheduledAncestor = []; $moduleNumber = 0; $assessmentModuleNumber = null;
        foreach ($rows as &$row) {
            $parent = $row['parent_node_id'] === null ? 0 : (int) $row['parent_node_id'];
            $ancestorUnlock = $parent === 0 ? null : ($scheduledAncestor[$parent] ?? null);
            $previousUnlock = $lastByParent[$parent] ?? ($parent === 0 ? 0 : ($availableByNode[$parent] ?? 0));
            $own = (int) $row['relative_delay_minutes'];
            $unlock = $ancestorUnlock ?? ($previousUnlock + ($publicOnly ? 0 : $own));
            $availableByNode[(int) $row['id']] = $unlock; $lastByParent[$parent] = $unlock;
            $scheduledAncestor[(int) $row['id']] = $ancestorUnlock ?? (((string) $row['node_type'] === 'section' && $own > 0) ? $unlock : null);
            if (($row['assessment_role'] ?? '') === 'final') { $unlock = 0; }
            $row['unlock_minutes'] = $publicOnly ? 0 : $unlock;
            $unlockTimestamp = $startedAt === null ? null : (strtotime($startedAt) ?: time()) + $unlock * 60;
            $row['unlock_at'] = $unlockTimestamp === null ? null : gmdate('Y-m-d H:i:sP', $unlockTimestamp);
            $row['is_locked'] = !$publicOnly && empty($row['public_preview']) && $unlockTimestamp !== null && $unlockTimestamp > time();
            $row['remaining_delay_label'] = self::duration($unlockTimestamp === null ? 0 : (int) ceil(max(0, $unlockTimestamp - time()) / 60));
            $row['delay_label'] = self::duration($own);
            $isReviewStudyAid = $row['node_type'] === 'item' && $row['item_type'] === 'html_lesson' && $this->isReviewStudyAid($row);
            $isHtmlModule = $row['node_type'] === 'item' && $row['item_type'] === 'html_lesson' && !$isReviewStudyAid;
            if ($isHtmlModule) { $moduleNumber++; $assessmentModuleNumber = $moduleNumber; }
            if ($isReviewStudyAid) { $assessmentModuleNumber = null; }
            $row['is_review_study_aid'] = $isReviewStudyAid;
            $row['is_final_assessment'] = ($row['assessment_role'] ?? '') === 'final';
            $row['module_number'] = $isHtmlModule ? $moduleNumber : null;
            $row['assessment_module_number'] = !$row['is_final_assessment'] && !$isHtmlModule && !$isReviewStudyAid && $row['node_type'] === 'item' && in_array($row['item_type'], ['assessment', 'diagnostic'], true) ? $assessmentModuleNumber : null;
        }
        unset($row);
        return $rows;
    }

    /** @param array<string,mixed> $row */
    private function isReviewStudyAid(array $row): bool
    {
        if (($row['type_config']['presentation_role'] ?? null) === 'review_study_aid') { return true; }
        return (string) ($row['item_title'] ?? '') === 'Review Study Aid' && str_ends_with((string) ($row['item_key'] ?? ''), '-review');
    }

    public static function duration(int $minutes): string
    {
        $minutes = max(0, $minutes); $weeks = intdiv($minutes, 10080); $minutes %= 10080; $days = intdiv($minutes, 1440); $minutes %= 1440; $hours = intdiv($minutes, 60); $minutes %= 60;
        return sprintf('%02d weeks %02d days %02d hours %02d minutes', $weeks, $days, $hours, $minutes);
    }

    /** @return list<string> */
    public function shortcodes(string $source): array
    {
        preg_match_all('/\[course-item:([a-zA-Z0-9][a-zA-Z0-9._-]{0,119})\]/', $source, $matches);
        return $matches[1];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function validateItem(array $input, int $currentId = 0): array
    {
        $key = trim((string) ($input['item_key'] ?? '')); $type = trim((string) ($input['item_type'] ?? '')); $title = trim((string) ($input['title'] ?? ''));
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,119}$/', $key) !== 1) { throw new InvalidArgumentException('Use a case-sensitive human-readable key containing letters, numbers, dots, underscores or hyphens.'); }
        $existing = $this->items->itemByKey($key);
        if ($existing !== null && (int) $existing['id'] !== $currentId) { throw new InvalidArgumentException('Another Course Item already uses that key.'); }
        if (!in_array($type, self::TYPES, true) || $title === '') { throw new InvalidArgumentException('Course Item type and title are required.'); }
        $resource = $this->nullableId($input['resource_id'] ?? null);
        $config = $input['type_config'] ?? [];
        if (!is_array($config)) { $config = []; }
        foreach (['youtube_id','remote_uri','caption','transcript','poster_resource_id','subtitle_resource_id'] as $field) {
            if (array_key_exists($field, $input)) { $config[$field] = trim((string) $input[$field]); }
        }
        return ['item_key' => $key, 'item_type' => $type, 'title' => $title, 'description_html' => $this->html->preserve((string) ($input['description_html'] ?? '')), 'content_source' => $this->html->preserve((string) ($input['content_source'] ?? '')), 'type_config' => $config, 'resource_id' => $resource];
    }

    /** @param array<string,mixed> $input */
    private function saveTypeDetails(int $id, string $type, array $input): void
    {
        if (!in_array($type, ['assessment','diagnostic'], true)) { return; }
        $this->items->saveAssessmentConfig($id, [
            'instructions_html' => $this->html->preserve((string) ($input['instructions_html'] ?? '')), 'result_pass_html' => $this->html->preserve((string) ($input['result_pass_html'] ?? '')), 'result_fail_html' => $this->html->preserve((string) ($input['result_fail_html'] ?? '')),
            'pass_mark' => min(100, max(0, (float) ($input['pass_mark'] ?? 50))), 'practice' => $type === 'assessment' && !empty($input['practice']), 'practice_enabled' => !empty($input['practice_enabled']),
            'practice_pool_mode' => in_array((string) ($input['practice_pool_mode'] ?? 'both'), ['separate','graded','both'], true) ? (string) ($input['practice_pool_mode'] ?? 'both') : 'both',
            'practice_question_count' => max(1, (int) ($input['practice_question_count'] ?? 5)), 'graded_question_count' => max(1, (int) ($input['graded_question_count'] ?? 1)),
            'maximum_attempts' => trim((string) ($input['maximum_attempts'] ?? '')) === '' ? null : max(1, (int) $input['maximum_attempts']), 'time_limit_seconds' => max(60, (int) ($input['time_limit_seconds'] ?? 1800)),
            'score_policy' => in_array((string) ($input['score_policy'] ?? 'highest'), ['highest','average','latest'], true) ? (string) ($input['score_policy'] ?? 'highest') : 'highest',
            'randomise_questions' => !empty($input['randomise_questions']), 'randomise_options' => !empty($input['randomise_options']), 'negative_marking' => !empty($input['negative_marking']), 'difficulty_selection' => [],
        ]);
        if (isset($input['questions']) && is_array($input['questions'])) {
            $this->items->replaceQuestions($id, array_values($input['questions']));
        } elseif (isset($input['question_html'])) {
            $this->items->replaceQuestions($id, $this->questionEditorInput($input));
        }
    }

    /**
     * @param array<string,mixed> $input
     * @return list<array<string,mixed>>
     */
    private function questionEditorInput(array $input): array
    {
        $questions = [];
        foreach ((array) ($input['question_html'] ?? []) as $index => $questionHtml) {
            $questionHtml = $this->html->preserve((string) $questionHtml);
            if ($questionHtml === '') { continue; }
            $rawOptions = (array) (($input['option_html'][$index] ?? []));
            $correct = (int) ($input['correct_option'][$index] ?? -1);
            $options = [];
            foreach ($rawOptions as $optionIndex => $optionHtml) {
                $optionHtml = $this->html->preserve((string) $optionHtml);
                if ($optionHtml === '') { continue; }
                $options[] = ['id' => (int) ($input['option_id'][$index][$optionIndex] ?? 0), 'option_html' => $optionHtml, 'is_correct' => (int) $optionIndex === $correct];
            }
            if (count($options) < 2 || count(array_filter($options, static fn(array $option): bool => $option['is_correct'])) !== 1) {
                throw new InvalidArgumentException('Every assessment question needs at least two options and exactly one correct answer.');
            }
            $questions[] = [
                'id' => (int) ($input['question_id'][$index] ?? 0),
                'question_html' => $questionHtml,
                'points' => max(1, (int) ($input['question_points'][$index] ?? 1)),
                'explanation_html' => $this->html->preserve((string) ($input['question_explanation'][$index] ?? '')),
                'difficulty' => in_array((string) ($input['question_difficulty'][$index] ?? 'standard'), ['introductory','standard','advanced'], true) ? (string) ($input['question_difficulty'][$index] ?? 'standard') : 'standard',
                'practice_eligible' => isset($input['practice_eligible'][$index]),
                'graded_eligible' => isset($input['graded_eligible'][$index]),
                'incorrect_points' => min(0, (float) ($input['incorrect_points'][$index] ?? 0)),
                'remediation_item_keys' => array_values(array_filter(array_map('trim', preg_split('/[,\s]+/', (string) ($input['remediation_item_keys'][$index] ?? '')) ?: []))),
                'options' => $options,
            ];
        }
        return $questions;
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function placementData(int $courseId, array $item, array $input): array
    {
        $delay = $this->delay($input);
        $role = (string) ($input['assessment_role'] ?? 'content');
        if (!in_array($role, ['content','graded','final'], true)) { $role = 'content'; }
        if ((string) $item['item_type'] !== 'assessment' && $role !== 'content') { throw new InvalidArgumentException('Only Assessment Course Items can be graded or final placements.'); }
        if ((string) $item['item_type'] === 'assessment' && empty($item['practice']) && $role === 'content') { $role = 'graded'; }
        if ($role === 'final' && ($delay !== 0 || !empty($item['practice']))) { throw new InvalidArgumentException('A final assessment cannot be practice or have a time delay.'); }
        return ['display_title_override' => trim((string) ($input['display_title_override'] ?? '')) ?: null, 'display_description_override' => trim((string) ($input['display_description_override'] ?? '')) ?: null, 'public_preview' => !empty($input['public_preview']), 'assessment_role' => $role, 'relative_delay_minutes' => $delay];
    }

    /** @param array<string,mixed> $input */
    private function delay(array $input): int
    {
        return max(0, (int) ($input['delay_weeks'] ?? 0)) * 10080 + max(0, (int) ($input['delay_days'] ?? 0)) * 1440 + max(0, (int) ($input['delay_hours'] ?? 0)) * 60 + max(0, (int) ($input['delay_minutes'] ?? $input['relative_delay_minutes'] ?? 0));
    }

    private function nullableId(mixed $value): ?int { $id = (int) $value; return $id > 0 ? $id : null; }
    /** @param array<string,mixed> $row */
    private function title(array $row): string { return trim((string) ($row['display_title_override'] ?? '')) ?: (string) ($row['item_title'] ?? $row['section_title'] ?? 'Untitled'); }
}

<?php

declare(strict_types=1);

namespace CattoLearning\Course;

use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Support\Uuid;
use RuntimeException;

/** Persists reusable Course Items, Resources and course-specific structure without owning business decisions. */
final class CourseItemRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function item(int $id): ?array
    {
        $row = $this->db->fetchAssociative($this->itemSelect() . ' WHERE ci.id=:id', ['id' => $id]);
        return $row === false ? null : $this->normalise($row);
    }

    /** @return array<string,mixed>|null */
    public function itemByKey(string $key): ?array
    {
        $row = $this->db->fetchAssociative($this->itemSelect() . ' WHERE ci.item_key=:key', ['key' => $key]);
        return $row === false ? null : $this->normalise($row);
    }

    /** @return list<array<string,mixed>> */
    public function reachableItems(int $rootItemId): array
    {
        return array_map($this->normalise(...), $this->db->fetchAllAssociative(
            'WITH RECURSIVE reachable(id) AS (
                SELECT id FROM course_items WHERE id=:root
                UNION
                SELECT target.id FROM reachable x JOIN course_items source ON source.id=x.id
                JOIN course_item_references r ON r.source_course_item_id=source.id
                JOIN course_items target ON target.item_key=r.referenced_key
                WHERE source.item_type=\'html_lesson\'
             ) ' . $this->itemSelect() . ' JOIN reachable x ON x.id=ci.id ORDER BY ci.id',
            ['root' => $rootItemId]
        ));
    }

    /** @return list<array<string,mixed>> */
    public function resourceContexts(int $resourceId): array
    {
        return $this->db->fetchAllAssociative(
            "WITH RECURSIVE uses(id,item_key) AS (
                SELECT id,item_key FROM course_items WHERE resource_id=:resource
                   OR type_config->>'poster_resource_id'=:resource_text OR type_config->>'subtitle_resource_id'=:resource_text
                UNION
                SELECT source.id,source.item_key FROM uses u JOIN course_item_references r ON r.referenced_key=u.item_key
                JOIN course_items source ON source.id=r.source_course_item_id WHERE source.item_type='html_lesson'
             ) SELECT DISTINCT p.course_id,p.node_id,p.public_preview,c.status FROM uses u
             JOIN course_item_placements p ON p.course_item_id=u.id JOIN courses c ON c.id=p.course_id",
            ['resource' => $resourceId, 'resource_text' => (string) $resourceId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function libraryPlacements(): array
    {
        return $this->db->fetchAllAssociative('SELECT DISTINCT c.id AS course_id,c.title AS course_title,p.course_item_id FROM course_item_placements p JOIN courses c ON c.id=p.course_id ORDER BY c.title,c.id,p.course_item_id');
    }

    /** @return list<array<string,mixed>> */
    public function items(string $search = '', string $type = ''): array
    {
        $where = ['1=1'];
        $params = [];
        if ($search !== '') {
            $where[] = '(ci.title ILIKE :search OR ci.item_key ILIKE :search)';
            $params['search'] = '%' . $search . '%';
        }
        if ($type !== '') {
            $where[] = 'ci.item_type=:type';
            $params['type'] = $type;
        }
        return array_map($this->normalise(...), $this->db->fetchAllAssociative(
            $this->itemSelect() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY ci.title,ci.item_key,ci.id',
            $params
        ));
    }

    /** @param array<string,mixed> $data */
    public function createItem(array $data, int $userId): int
    {
        $row = $this->db->fetchAssociative(
            'INSERT INTO course_items (public_id,item_key,item_type,title,description_html,content_source,type_config,resource_id,created_by_user_id,updated_by_user_id,created_at,updated_at)
             VALUES (:public_id,:item_key,:item_type,:title,:description_html,:content_source,:type_config,:resource_id,:user_id,:user_id,NOW(),NOW()) RETURNING id',
            [
                'public_id' => Uuid::v4(), 'item_key' => $data['item_key'], 'item_type' => $data['item_type'],
                'title' => $data['title'], 'description_html' => $data['description_html'],
                'content_source' => $data['content_source'], 'type_config' => json_encode($data['type_config'], JSON_THROW_ON_ERROR),
                'resource_id' => $data['resource_id'], 'user_id' => $userId,
            ]
        );
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1) {
            throw new RuntimeException('Unable to create the Course Item.');
        }
        return $id;
    }

    /** @param array<string,mixed> $data */
    public function updateItem(int $id, array $data, int $userId): void
    {
        if ($this->db->executeStatement(
            'UPDATE course_items SET item_key=:item_key,item_type=:item_type,title=:title,description_html=:description_html,content_source=:content_source,type_config=:type_config,resource_id=:resource_id,updated_by_user_id=:user_id,updated_at=NOW() WHERE id=:id',
            [
                'id' => $id, 'item_key' => $data['item_key'], 'item_type' => $data['item_type'], 'title' => $data['title'],
                'description_html' => $data['description_html'], 'content_source' => $data['content_source'],
                'type_config' => json_encode($data['type_config'], JSON_THROW_ON_ERROR), 'resource_id' => $data['resource_id'], 'user_id' => $userId,
            ]
        ) === 0) {
            throw new RuntimeException('The Course Item does not exist.');
        }
    }

    /** @param list<string> $keys */
    public function replaceReferences(int $sourceItemId, array $keys): void
    {
        $this->db->executeStatement('DELETE FROM course_item_references WHERE source_course_item_id=:id', ['id' => $sourceItemId]);
        foreach (array_count_values($keys) as $key => $count) {
            $this->db->executeStatement(
                'INSERT INTO course_item_references (source_course_item_id,referenced_key,occurrence_count) VALUES (:source,:key,:count)',
                ['source' => $sourceItemId, 'key' => $key, 'count' => $count]
            );
        }
    }

    /** @return list<array<string,mixed>> */
    public function usage(int $itemId, string $key): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT 'placement' AS usage_type,c.id AS course_id,c.title AS course_title,n.id AS node_id,NULL::bigint AS source_item_id,NULL::text AS source_item_title
             FROM course_item_placements p JOIN course_structure_nodes n ON n.id=p.node_id JOIN courses c ON c.id=p.course_id WHERE p.course_item_id=:item_id
             UNION ALL
             SELECT 'embedded',c.id,c.title,n.id,source.id,source.title
             FROM course_item_references r JOIN course_items source ON source.id=r.source_course_item_id
             LEFT JOIN course_item_placements p ON p.course_item_id=source.id LEFT JOIN course_structure_nodes n ON n.id=p.node_id LEFT JOIN courses c ON c.id=p.course_id
             WHERE r.referenced_key=:key
             UNION ALL
             SELECT 'remediation',c.id,c.title,n.id,source.id,source.title
             FROM assessment_questions q JOIN course_items source ON source.id=q.course_item_id
             LEFT JOIN course_item_placements p ON p.course_item_id=source.id LEFT JOIN course_structure_nodes n ON n.id=p.node_id LEFT JOIN courses c ON c.id=p.course_id
             WHERE q.remediation_item_keys @> jsonb_build_array(CAST(:key AS text)) ORDER BY course_title NULLS LAST,usage_type,source_item_title",
            ['item_id' => $itemId, 'key' => $key]
        );
    }

    /** @return list<array<string,mixed>> */
    public function courseUsage(int $itemId): array
    {
        return $this->db->fetchAllAssociative("WITH RECURSIVE uses(id) AS (
            SELECT id FROM course_items WHERE id=:item
            UNION
            SELECT source.id FROM uses u JOIN course_items target ON target.id=u.id
            JOIN course_item_references r ON r.referenced_key=target.item_key
            JOIN course_items source ON source.id=r.source_course_item_id WHERE source.item_type='html_lesson'
        ) SELECT DISTINCT c.id,c.title FROM uses u JOIN course_item_placements p ON p.course_item_id=u.id JOIN courses c ON c.id=p.course_id ORDER BY c.title,c.id", ['item' => $itemId]);
    }

    public function deleteItem(int $id): void
    {
        $this->db->executeStatement('DELETE FROM course_items WHERE id=:id', ['id' => $id]);
    }

    /** @return list<array<string,mixed>> */
    public function structure(int $courseId, bool $publicOnly = false): array
    {
        $public = $publicOnly ? ' AND (n.node_type=\'section\' OR p.public_preview=TRUE)' : '';
        $rows = $this->db->fetchAllAssociative(
            "WITH RECURSIVE tree AS (
                SELECT n.*,1 AS depth,ARRAY[n.position,n.id::int] AS order_path FROM course_structure_nodes n WHERE n.course_id=:course_id AND n.parent_node_id IS NULL
                UNION ALL
                SELECT n.*,t.depth+1,t.order_path||ARRAY[n.position,n.id::int] FROM course_structure_nodes n JOIN tree t ON t.id=n.parent_node_id
             )
             SELECT n.*,s.title AS section_title,s.introduction_html AS section_introduction_html,s.show_outline,
                    p.course_item_id,p.display_title_override,p.display_description_override,p.public_preview,p.assessment_role,
                    ci.item_key,ci.item_type,ci.title AS item_title,ci.description_html,ci.content_source,ci.type_config,ci.resource_id,
                    r.public_id AS resource_public_id,r.filename AS resource_filename,r.original_filename,r.resource_type,r.mime_type,r.byte_size,r.description AS resource_description,
                    a.instructions_html,a.result_pass_html,a.result_fail_html,a.pass_mark,a.practice,a.practice_enabled,a.practice_pool_mode,
                    a.practice_question_count,a.graded_question_count,a.maximum_attempts,a.time_limit_seconds,a.score_policy,a.randomise_questions,a.randomise_options,a.negative_marking,a.difficulty_selection
             FROM tree n LEFT JOIN course_sections s ON s.node_id=n.id LEFT JOIN course_item_placements p ON p.node_id=n.id
             LEFT JOIN course_items ci ON ci.id=p.course_item_id LEFT JOIN resources r ON r.id=ci.resource_id LEFT JOIN course_item_assessments a ON a.course_item_id=ci.id
             WHERE 1=1{$public} ORDER BY n.order_path",
            ['course_id' => $courseId]
        );
        return array_map($this->normalise(...), $rows);
    }

    /** @return array<string,mixed>|null */
    public function placement(int $courseId, int $nodeId): ?array
    {
        foreach ($this->structure($courseId) as $row) {
            if ((int) $row['id'] === $nodeId && (string) $row['node_type'] === 'item') {
                return $row;
            }
        }
        return null;
    }

    public function nextPosition(int $courseId, ?int $parentId): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COALESCE(MAX(position),0)+1 FROM course_structure_nodes WHERE course_id=:course AND parent_node_id IS NOT DISTINCT FROM :parent',
            ['course' => $courseId, 'parent' => $parentId]
        );
    }

    public function createSection(int $courseId, ?int $parentId, int $position, string $title, string $introduction, bool $showOutline, int $delay): int
    {
        $row = $this->db->fetchAssociative(
            "INSERT INTO course_structure_nodes (public_id,course_id,parent_node_id,position,node_type,relative_delay_minutes) VALUES (:public_id,:course,:parent,:position,'section',:delay) RETURNING id",
            ['public_id' => Uuid::v4(), 'course' => $courseId, 'parent' => $parentId, 'position' => $position, 'delay' => $delay]
        );
        $id = (int) ($row['id'] ?? 0);
        $this->db->executeStatement(
            'INSERT INTO course_sections (node_id,title,introduction_html,show_outline) VALUES (:id,:title,:intro,:outline)',
            ['id' => $id, 'title' => $title, 'intro' => $introduction, 'outline' => $showOutline]
        );
        return $id;
    }

    /** @param array<string,mixed> $data */
    public function createPlacement(int $courseId, int $itemId, ?int $parentId, int $position, array $data): int
    {
        $row = $this->db->fetchAssociative(
            "INSERT INTO course_structure_nodes (public_id,course_id,parent_node_id,position,node_type,relative_delay_minutes) VALUES (:public_id,:course,:parent,:position,'item',:delay) RETURNING id",
            ['public_id' => Uuid::v4(), 'course' => $courseId, 'parent' => $parentId, 'position' => $position, 'delay' => $data['relative_delay_minutes']]
        );
        $id = (int) ($row['id'] ?? 0);
        $this->db->executeStatement(
            'INSERT INTO course_item_placements (node_id,course_id,course_item_id,display_title_override,display_description_override,public_preview,assessment_role) VALUES (:node,:course,:item,:title,:description,:public,:role)',
            ['node' => $id, 'course' => $courseId, 'item' => $itemId, 'title' => $data['display_title_override'], 'description' => $data['display_description_override'], 'public' => $data['public_preview'], 'role' => $data['assessment_role']]
        );
        return $id;
    }

    /** @param array<string,mixed> $data */
    public function updatePlacement(int $nodeId, array $data): void
    {
        $this->db->executeStatement('UPDATE course_structure_nodes SET relative_delay_minutes=:delay,updated_at=NOW() WHERE id=:id', ['delay' => $data['relative_delay_minutes'], 'id' => $nodeId]);
        $this->db->executeStatement(
            'UPDATE course_item_placements SET display_title_override=:title,display_description_override=:description,public_preview=:public,assessment_role=:role WHERE node_id=:id',
            ['id' => $nodeId, 'title' => $data['display_title_override'], 'description' => $data['display_description_override'], 'public' => $data['public_preview'], 'role' => $data['assessment_role']]
        );
    }

    public function removeNode(int $courseId, int $nodeId): void
    {
        $this->db->executeStatement('DELETE FROM course_structure_nodes WHERE id=:id AND course_id=:course', ['id' => $nodeId, 'course' => $courseId]);
    }

    public function moveNode(int $courseId, int $nodeId, ?int $parentId, int $position): void
    {
        $this->db->executeStatement(
            'UPDATE course_structure_nodes SET parent_node_id=:parent,position=:position,updated_at=NOW() WHERE id=:id AND course_id=:course',
            ['parent' => $parentId, 'position' => $position, 'id' => $nodeId, 'course' => $courseId]
        );
    }

    /** @return array<string,mixed>|null */
    public function node(int $courseId, int $nodeId): ?array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM course_structure_nodes WHERE id=:id AND course_id=:course', ['id' => $nodeId, 'course' => $courseId]);
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function siblings(int $courseId, ?int $parentId): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT * FROM course_structure_nodes WHERE course_id=:course AND parent_node_id IS NOT DISTINCT FROM :parent ORDER BY position,id',
            ['course' => $courseId, 'parent' => $parentId]
        );
    }

    public function swapPositions(int $courseId, int $firstId, int $secondId): void
    {
        $first = $this->node($courseId, $firstId); $second = $this->node($courseId, $secondId);
        if ($first === null || $second === null || ($first['parent_node_id'] ?? null) !== ($second['parent_node_id'] ?? null)) { return; }
        $temporary = $this->nextPosition($courseId, $first['parent_node_id'] === null ? null : (int) $first['parent_node_id']);
        $this->db->executeStatement('UPDATE course_structure_nodes SET position=:position WHERE id=:id', ['position' => $temporary, 'id' => $firstId]);
        $this->db->executeStatement('UPDATE course_structure_nodes SET position=:position WHERE id=:id', ['position' => (int) $first['position'], 'id' => $secondId]);
        $this->db->executeStatement('UPDATE course_structure_nodes SET position=:position WHERE id=:id', ['position' => (int) $second['position'], 'id' => $firstId]);
    }

    public function updateSection(int $nodeId, string $title, string $introduction, bool $outline, int $delay): void
    {
        $this->db->executeStatement('UPDATE course_sections SET title=:title,introduction_html=:intro,show_outline=:outline WHERE node_id=:id', ['title' => $title, 'intro' => $introduction, 'outline' => $outline, 'id' => $nodeId]);
        $this->db->executeStatement('UPDATE course_structure_nodes SET relative_delay_minutes=:delay,updated_at=NOW() WHERE id=:id', ['delay' => $delay, 'id' => $nodeId]);
    }

    /** SQL join for a paged enrolment row named ce, including embedded assessments once per placement. */
    public static function assessmentProgressJoin(): string
    {
        return " LEFT JOIN LATERAL (
            WITH RECURSIVE reachable(node_id,item_id) AS (
                SELECT p.node_id,p.course_item_id FROM course_item_placements p WHERE p.course_id=ce.course_id
                UNION
                SELECT x.node_id,target.id FROM reachable x JOIN course_items source ON source.id=x.item_id
                JOIN course_item_references r ON r.source_course_item_id=source.id
                JOIN course_items target ON target.item_key=r.referenced_key WHERE source.item_type='html_lesson'
            )
            SELECT COUNT(*)::int AS assessment_count,
                   COUNT(*) FILTER (WHERE EXISTS (SELECT 1 FROM assessment_attempts aa WHERE aa.enrolment_id=ce.id AND aa.structure_node_id=x.node_id AND aa.course_item_id=x.item_id))::int AS submitted_assessment_count
            FROM reachable x JOIN course_items ci ON ci.id=x.item_id JOIN course_item_assessments a ON a.course_item_id=x.item_id
            WHERE ci.item_type='assessment' AND a.practice=FALSE
        ) assessment_progress ON TRUE ";
    }

    public function hasActiveLearners(int $courseId): bool
    {
        return (int) $this->db->fetchOne("SELECT COUNT(*) FROM course_enrolments WHERE course_id=:course AND is_preview=FALSE AND status='active' AND started_at IS NOT NULL AND access_removed_at IS NULL AND (expires_at IS NULL OR expires_at>NOW())", ['course' => $courseId]) > 0;
    }

    /**
     * Resolves each assessment in its course placement context, including embedded assessments.
     * UNION terminates cycles while publication validation reports them to the author.
     * @return list<array<string,mixed>>
     */
    public function assessmentContexts(int $courseId, ?int $enrolmentId = null): array
    {
        return array_map($this->normalise(...), $this->db->fetchAllAssociative(
            "WITH RECURSIVE contexts(node_id,root_item_id,item_id,role) AS (
                SELECT node_id,course_item_id,course_item_id,assessment_role::varchar FROM course_item_placements WHERE course_id=:course
                UNION
                SELECT x.node_id,x.root_item_id,target.id,'graded'::varchar FROM contexts x
                JOIN course_items source ON source.id=x.item_id
                JOIN course_item_references r ON r.source_course_item_id=source.id
                JOIN course_items target ON target.item_key=r.referenced_key WHERE source.item_type='html_lesson'
             )
             SELECT x.node_id,x.item_id AS course_item_id,ci.item_key,a.score_policy,a.practice,
                    CASE WHEN x.root_item_id=x.item_id AND x.role='final' THEN 'final' ELSE 'graded' END AS assessment_role,
                    EXISTS(SELECT 1 FROM assessment_attempts aa WHERE aa.enrolment_id=:enrolment AND aa.structure_node_id=x.node_id AND aa.course_item_id=x.item_id) AS submitted
             FROM contexts x JOIN course_items ci ON ci.id=x.item_id JOIN course_item_assessments a ON a.course_item_id=ci.id
             WHERE ci.item_type='assessment' AND a.practice=FALSE",
            ['course' => $courseId, 'enrolment' => $enrolmentId]
        ));
    }

    /** @return array{total:int,submitted:int,percentage:int} */
    public function assessmentProgress(int $enrolmentId, int $courseId): array
    {
        $contexts = $this->assessmentContexts($courseId, $enrolmentId);
        $total = count($contexts);
        $submitted = count(array_filter($contexts, static fn(array $row): bool => (bool) $row['submitted']));
        return ['total' => $total, 'submitted' => $submitted, 'percentage' => $total > 0 ? (int) round($submitted / $total * 100) : 0];
    }

    public function precedingGradedAssessmentsSubmitted(int $enrolmentId, int $courseId, int $finalNodeId): bool
    {
        $preceding = [];
        foreach ($this->structure($courseId) as $node) {
            if ((int) $node['id'] === $finalNodeId) { break; }
            $preceding[(int) $node['id']] = true;
        }
        foreach ($this->assessmentContexts($courseId, $enrolmentId) as $context) {
            if (isset($preceding[(int) $context['node_id']]) && !$context['submitted']) { return false; }
        }
        return true;
    }

    /** @param array<string,mixed> $data */
    public function saveAssessmentConfig(int $itemId, array $data): void
    {
        $this->db->executeStatement(
            'INSERT INTO course_item_assessments (course_item_id,instructions_html,result_pass_html,result_fail_html,pass_mark,practice,practice_enabled,practice_pool_mode,practice_question_count,graded_question_count,maximum_attempts,time_limit_seconds,score_policy,randomise_questions,randomise_options,negative_marking,difficulty_selection)
             VALUES (:id,:instructions,:pass_html,:fail_html,:pass_mark,:practice,:practice_enabled,:pool,:practice_count,:graded_count,:maximum_attempts,:time_limit,:score_policy,:random_questions,:random_options,:negative,:difficulty)
             ON CONFLICT (course_item_id) DO UPDATE SET instructions_html=EXCLUDED.instructions_html,result_pass_html=EXCLUDED.result_pass_html,result_fail_html=EXCLUDED.result_fail_html,pass_mark=EXCLUDED.pass_mark,practice=EXCLUDED.practice,practice_enabled=EXCLUDED.practice_enabled,practice_pool_mode=EXCLUDED.practice_pool_mode,practice_question_count=EXCLUDED.practice_question_count,graded_question_count=EXCLUDED.graded_question_count,maximum_attempts=EXCLUDED.maximum_attempts,time_limit_seconds=EXCLUDED.time_limit_seconds,score_policy=EXCLUDED.score_policy,randomise_questions=EXCLUDED.randomise_questions,randomise_options=EXCLUDED.randomise_options,negative_marking=EXCLUDED.negative_marking,difficulty_selection=EXCLUDED.difficulty_selection,updated_at=NOW()',
            [
                'id' => $itemId, 'instructions' => $data['instructions_html'], 'pass_html' => $data['result_pass_html'], 'fail_html' => $data['result_fail_html'],
                'pass_mark' => $data['pass_mark'], 'practice' => $data['practice'], 'practice_enabled' => $data['practice_enabled'], 'pool' => $data['practice_pool_mode'],
                'practice_count' => $data['practice_question_count'], 'graded_count' => $data['graded_question_count'], 'maximum_attempts' => $data['maximum_attempts'],
                'time_limit' => $data['time_limit_seconds'], 'score_policy' => $data['score_policy'], 'random_questions' => $data['randomise_questions'],
                'random_options' => $data['randomise_options'], 'negative' => $data['negative_marking'], 'difficulty' => json_encode($data['difficulty_selection'], JSON_THROW_ON_ERROR),
            ]
        );
    }

    /** @return list<array<string,mixed>> */
    public function questions(int $itemId, bool $includeCorrect): array
    {
        $questions = $this->db->fetchAllAssociative('SELECT * FROM assessment_questions WHERE course_item_id=:id ORDER BY position,id', ['id' => $itemId]);
        $options = $questions === [] ? [] : $this->db->fetchAllAssociative(
            'SELECT * FROM assessment_options WHERE question_id IN (:ids) ORDER BY question_id,position,id',
            ['ids' => array_map(static fn(array $q): int => (int) $q['id'], $questions)]
        );
        $byQuestion = [];
        foreach ($options as $option) {
            if (!$includeCorrect) {
                unset($option['is_correct']);
            } else {
                $option['is_correct'] = $this->boolean($option['is_correct']);
            }
            $byQuestion[(int) $option['question_id']][] = $option;
        }
        foreach ($questions as &$question) {
            $question['remediation_item_keys'] = $this->json($question['remediation_item_keys']);
            $question['practice_eligible'] = $this->boolean($question['practice_eligible']);
            $question['graded_eligible'] = $this->boolean($question['graded_eligible']);
            $question['options'] = $byQuestion[(int) $question['id']] ?? [];
        }
        unset($question);
        return $questions;
    }

    /** @param list<array<string,mixed>> $questions */
    public function replaceQuestions(int $itemId, array $questions): void
    {
        $existing = $this->questions($itemId, true);
        $existingById = [];
        foreach ($existing as $question) { $existingById[(int) $question['id']] = $question; }
        $offset = count($questions) + count($existing) + (int) $this->db->fetchOne('SELECT COALESCE(MAX(position),0) FROM assessment_questions WHERE course_item_id=:id', ['id' => $itemId]) + 1;
        $this->db->executeStatement('UPDATE assessment_questions SET position=position+:offset WHERE course_item_id=:id', ['offset' => $offset, 'id' => $itemId]);
        $kept = [];
        foreach ($questions as $questionPosition => $question) {
            $questionId = (int) ($question['id'] ?? 0);
            if ($questionId > 0 && !isset($existingById[$questionId])) { throw new \InvalidArgumentException('The question does not belong to this Course Item.'); }
            $params = [
                'item' => $itemId, 'position' => $questionPosition + 1,
                'question' => $question['question_html'], 'points' => $question['points'], 'difficulty' => $question['difficulty'] ?? 'standard',
                'practice' => $question['practice_eligible'] ?? true, 'graded' => $question['graded_eligible'] ?? true,
                'incorrect' => min(0, (float) ($question['incorrect_points'] ?? 0)),
                'remediation' => json_encode(array_values((array) ($question['remediation_item_keys'] ?? [])), JSON_THROW_ON_ERROR),
                'explanation' => $question['explanation_html'] ?? '',
            ];
            if ($questionId > 0) {
                $this->db->executeStatement('UPDATE assessment_questions SET position=:position,question_html=:question,points=:points,difficulty=:difficulty,practice_eligible=:practice,graded_eligible=:graded,incorrect_points=:incorrect,remediation_item_keys=:remediation,explanation_html=:explanation WHERE id=:id AND course_item_id=:item', $params + ['id' => $questionId]);
            } else {
                $questionId = (int) $this->db->fetchOne('INSERT INTO assessment_questions (public_id,course_item_id,position,question_html,points,difficulty,practice_eligible,graded_eligible,incorrect_points,remediation_item_keys,explanation_html) VALUES (:public_id,:item,:position,:question,:points,:difficulty,:practice,:graded,:incorrect,:remediation,:explanation) RETURNING id', $params + ['public_id' => Uuid::v4()]);
            }
            $kept[] = $questionId;
            $existingOptions = [];
            foreach (($existingById[$questionId]['options'] ?? []) as $option) { $existingOptions[(int) $option['id']] = $option; }
            $optionOffset = count((array) ($question['options'] ?? [])) + count($existingOptions) + (int) $this->db->fetchOne('SELECT COALESCE(MAX(position),0) FROM assessment_options WHERE question_id=:id', ['id' => $questionId]) + 1;
            $this->db->executeStatement('UPDATE assessment_options SET position=position+:offset,is_correct=FALSE WHERE question_id=:id', ['offset' => $optionOffset, 'id' => $questionId]);
            $keptOptions = [];
            foreach ((array) ($question['options'] ?? []) as $optionPosition => $option) {
                $optionId = (int) ($option['id'] ?? 0);
                if ($optionId > 0 && !isset($existingOptions[$optionId])) { throw new \InvalidArgumentException('The answer option does not belong to this question.'); }
                $params = ['question' => $questionId, 'position' => $optionPosition + 1, 'html' => $option['option_html'], 'correct' => $option['is_correct']];
                if ($optionId > 0) {
                    $this->db->executeStatement('UPDATE assessment_options SET position=:position,option_html=:html,is_correct=:correct WHERE id=:id AND question_id=:question', $params + ['id' => $optionId]);
                } else {
                    $optionId = (int) $this->db->fetchOne('INSERT INTO assessment_options (public_id,question_id,position,option_html,is_correct) VALUES (:public_id,:question,:position,:html,:correct) RETURNING id', $params + ['public_id' => Uuid::v4()]);
                }
                $keptOptions[] = $optionId;
            }
            foreach (array_diff(array_keys($existingOptions), $keptOptions) as $removedId) {
                if ((int) $this->db->fetchOne('SELECT (SELECT COUNT(*) FROM assessment_responses WHERE selected_option_id=:id)+(SELECT COUNT(*) FROM assessment_session_questions WHERE selected_option_id=:id)', ['id' => $removedId]) > 0) { throw new \InvalidArgumentException('An answer option is referenced by an assessment submission and cannot be removed. Save As can create an independent assessment.'); }
                $this->db->executeStatement('DELETE FROM assessment_options WHERE id=:id', ['id' => $removedId]);
            }
        }
        foreach (array_diff(array_keys($existingById), $kept) as $removedId) {
            if ((int) $this->db->fetchOne('SELECT (SELECT COUNT(*) FROM assessment_responses WHERE question_id=:id)+(SELECT COUNT(*) FROM assessment_session_questions WHERE question_id=:id)', ['id' => $removedId]) > 0) { throw new \InvalidArgumentException('A question is referenced by an assessment submission and cannot be removed. Save As can create an independent assessment.'); }
            $this->db->executeStatement('DELETE FROM assessment_questions WHERE id=:id', ['id' => $removedId]);
        }
    }

    /** @return list<array<string,mixed>> */
    public function resources(string $search = '', string $type = ''): array
    {
        $where = ['1=1']; $params = [];
        if ($search !== '') { $where[] = '(r.title ILIKE :search OR r.filename ILIKE :search)'; $params['search'] = '%' . $search . '%'; }
        if ($type !== '') { $where[] = 'r.resource_type=:type'; $params['type'] = $type; }
        return $this->db->fetchAllAssociative(
            'SELECT r.*,(SELECT COUNT(*) FROM course_items ci WHERE ci.resource_id=r.id OR ci.type_config->>\'poster_resource_id\'=r.id::text OR ci.type_config->>\'subtitle_resource_id\'=r.id::text)::int AS usage_count FROM resources r WHERE ' . implode(' AND ', $where) . ' ORDER BY r.title,r.id',
            $params
        );
    }

    /** @return array<string,mixed>|null */
    public function resource(int $id): ?array
    {
        $row = $this->db->fetchAssociative('SELECT *,(SELECT COUNT(*) FROM course_items ci WHERE ci.resource_id=resources.id OR ci.type_config->>\'poster_resource_id\'=resources.id::text OR ci.type_config->>\'subtitle_resource_id\'=resources.id::text)::int AS usage_count FROM resources WHERE id=:id', ['id' => $id]);
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function resourceByPublicId(string $publicId): ?array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM resources WHERE public_id=:public_id', ['public_id' => $publicId]);
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $data */
    public function createResource(array $data, int $userId): int
    {
        $row = $this->db->fetchAssociative(
            'INSERT INTO resources (public_id,title,description,filename,original_filename,resource_type,mime_type,byte_size,created_by_user_id,updated_at) VALUES (:public_id,:title,:description,:filename,:original,:type,:mime,:size,:user,:updated) RETURNING id',
            ['public_id' => Uuid::v4(), 'title' => $data['title'], 'description' => $data['description'], 'filename' => $data['filename'], 'original' => $data['original_filename'], 'type' => $data['resource_type'], 'mime' => $data['mime_type'], 'size' => $data['byte_size'], 'user' => $userId, 'updated' => $data['updated_at'] ?? date(DATE_ATOM)]
        );
        return (int) ($row['id'] ?? 0);
    }

    /** @return list<array<string,mixed>> */
    public function resourceUsage(int $id): array
    {
        return $this->db->fetchAllAssociative("SELECT id,item_key,title,item_type FROM course_items WHERE resource_id=:id OR type_config->>'poster_resource_id'=:text_id OR type_config->>'subtitle_resource_id'=:text_id ORDER BY title,id", ['id' => $id, 'text_id' => (string) $id]);
    }

    public function deleteResource(int $id): void
    {
        $this->db->executeStatement('DELETE FROM resources WHERE id=:id', ['id' => $id]);
    }

    private function itemSelect(): string
    {
        return 'SELECT ci.*,r.public_id AS resource_public_id,r.title AS resource_title,r.description AS resource_description,r.filename AS resource_filename,r.original_filename,r.resource_type,r.mime_type,r.byte_size,a.instructions_html,a.result_pass_html,a.result_fail_html,a.pass_mark,a.practice,a.practice_enabled,a.practice_pool_mode,a.practice_question_count,a.graded_question_count,a.maximum_attempts,a.time_limit_seconds,a.score_policy,a.randomise_questions,a.randomise_options,a.negative_marking,a.difficulty_selection FROM course_items ci LEFT JOIN resources r ON r.id=ci.resource_id LEFT JOIN course_item_assessments a ON a.course_item_id=ci.id';
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalise(array $row): array
    {
        foreach (['type_config','difficulty_selection'] as $field) {
            if (array_key_exists($field, $row)) { $row[$field] = $this->json($row[$field]); }
        }
        foreach (['public_preview','show_outline','practice','practice_enabled','randomise_questions','randomise_options','negative_marking','submitted'] as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null) { $row[$field] = $this->boolean($row[$field]); }
        }
        return $row;
    }

    /** @return array<mixed> */
    private function json(mixed $value): array
    {
        if (is_array($value)) { return $value; }
        $decoded = is_string($value) && $value !== '' ? json_decode($value, true) : [];
        return is_array($decoded) ? $decoded : [];
    }

    private function boolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}

<?php

declare(strict_types=1);

namespace CattoLearning\Course;

use CattoLearning\Commerce\Application\AccessService;
use CattoLearning\Infrastructure\Persistence\AuditRepository;
use CattoLearning\Infrastructure\Persistence\TransactionManager;
use CattoLearning\Support\Slug;
use InvalidArgumentException;
use RuntimeException;

/** Runs assessment and diagnostic Course Items in a placement context. */
final class AssessmentService
{
    public function __construct(private readonly TransactionManager $transactions, private readonly CourseRepository $courses, private readonly CourseItemRepository $itemRecords, private readonly CourseItemService $items, private readonly AssessmentRepository $assessments, private readonly AuditRepository $audit, private readonly ?AccessService $commerceAccess = null)
    {
    }

    /**
     * Anonymous preview deliberately writes no enrolment, attempt, progress or grade records.
     * @param array<int|string,mixed>|null $answers
     * @return array<string,mixed>
     */
    public function publicPreview(string $slug, int $nodeId, string $key, ?array $answers = null, bool $authorisedDraftPreview = false): array
    {
        $course = $this->courses->findBySlug(Slug::validate($slug), !$authorisedDraftPreview);
        if ($course === null) { throw new InvalidArgumentException('The course does not exist.'); }
        $context = $this->itemRecords->placement((int) $course['id'], $nodeId);
        if ($context === null || !$context['public_preview']) { throw new InvalidArgumentException('This Course Item is not public.'); }
        $assessment = null;
        foreach ($this->itemRecords->reachableItems((int) $context['course_item_id']) as $item) {
            if ($item['item_key'] === $key && in_array($item['item_type'], ['assessment','diagnostic'], true)) { $assessment = $item; break; }
        }
        if ($assessment === null) { throw new InvalidArgumentException('This assessment is not part of the public Course Item.'); }
        $questions = $this->itemRecords->questions((int) $assessment['id'], true);
        $score = 0.0; $possible = 0.0;
        foreach ($questions as &$question) {
            $selected = (int) ($answers[(int) $question['id']] ?? 0); $correct = false; $valid = false;
            foreach ($question['options'] as $option) {
                if ((int) $option['id'] === $selected) { $valid = true; $correct = (bool) $option['is_correct']; }
            }
            if ($selected !== 0 && !$valid) { throw new InvalidArgumentException('An answer does not belong to this question.'); }
            $possible += (float) $question['points'];
            $score += $correct ? (float) $question['points'] : ($selected && $assessment['negative_marking'] ? (float) $question['incorrect_points'] : 0);
            $question['selected_option_id'] = $selected;
            $question['answered_correctly'] = $correct;
        }
        unset($question);
        $course['structure'] = $this->items->availability((int) $course['id'], null, true);
        return ['course' => $course, 'assessment' => $assessment, 'questions' => $questions, 'submitted' => $answers !== null, 'percentage' => $possible > 0 ? round(max(0, $score) / $possible * 100, 2) : 0.0];
    }

    /** @return array<string,mixed> */
    public function overview(int $userId, string $slug, int $nodeId, string $itemKey, bool $preview = false): array
    {
        [$course, $enrolment] = $this->courseAndEnrolment($userId, $slug, $preview);
        $context = $this->itemRecords->placement((int) $course['id'], $nodeId);
        if ($context === null) { throw new InvalidArgumentException('The Course Content placement does not exist.'); }
        $assessment = null;
        foreach ($this->itemRecords->reachableItems((int) $context['course_item_id']) as $candidate) {
            if ($candidate['item_key'] === $itemKey) { $assessment = $candidate; break; }
        }
        if ($assessment === null) { throw new InvalidArgumentException('The assessment is not used by this Course Content placement.'); }
        foreach ($this->items->availability((int) $course['id'], (string) $enrolment['started_at'], false) as $node) {
            if ((int) $node['id'] === $nodeId && $node['is_locked'] && !$preview) { throw new InvalidArgumentException('This Course Item is not available yet.'); }
        }
        if (!in_array((string) $assessment['item_type'], ['assessment','diagnostic'], true)) { throw new InvalidArgumentException('That Course Item is not an assessment.'); }
        $assessment['question_pool_count'] = count($this->itemRecords->questions((int) $assessment['id'], false));
        $assessment['id'] = (int) $assessment['id']; $assessment['structure_node_id'] = $nodeId; $assessment['assessment_type'] = (string) $assessment['item_type']; $assessment['assessment_key'] = (string) $assessment['item_key'];
        $assessment['assessment_role'] = (($context['course_item_id'] ?? null) == $assessment['id']) ? (string) ($context['assessment_role'] ?? 'content') : 'content';
        if ($assessment['assessment_role'] === 'final' && !$preview && !$this->itemRecords->precedingGradedAssessmentsSubmitted((int) $enrolment['id'], (int) $course['id'], $nodeId)) { throw new InvalidArgumentException('Submit every preceding graded assessment before attempting the final assessment.'); }
        $attemptMode = $assessment['assessment_type'] === 'diagnostic' ? 'diagnostic' : 'graded';
        $used = $this->assessments->completedAttemptCount((int) $enrolment['id'], $assessment['id'], $nodeId, $attemptMode);
        $maximum = !empty($assessment['practice']) || $assessment['maximum_attempts'] === null || $assessment['maximum_attempts'] === '' ? null : (int) $assessment['maximum_attempts'];
        return ['course' => $course, 'enrolment' => $enrolment, 'assessment' => $assessment, 'attempts_used' => $used, 'attempts_remaining' => $maximum === null ? null : max(0, $maximum - $used), 'attempts_limited' => $maximum !== null, 'can_start_graded' => $maximum === null || $used < $maximum, 'is_preview' => $preview, 'is_diagnostic' => $assessment['assessment_type'] === 'diagnostic', 'time_limit_label' => $this->durationLabel((int) ($assessment['time_limit_seconds'] ?? 1800))];
    }

    /** @return array<string,mixed> */
    public function startAttempt(int $userId, string $slug, int $nodeId, string $itemKey, string $mode, bool $preview = false): array
    {
        $overview = $this->overview($userId, $slug, $nodeId, $itemKey, $preview); $assessment = (array) $overview['assessment']; $enrolment = (array) $overview['enrolment'];
        if (!in_array($mode, ['practice','graded','diagnostic'], true)) { throw new InvalidArgumentException('Select a valid assessment mode.'); }
        $diagnostic = $assessment['assessment_type'] === 'diagnostic';
        if (!$diagnostic && (bool) ($assessment['practice'] ?? false)) { $mode = 'practice'; }
        if (($diagnostic && $mode !== 'diagnostic') || (!$diagnostic && $mode === 'diagnostic')) { throw new InvalidArgumentException('Select the assessment mode that matches this Course Item.'); }
        if ($mode === 'practice' && !(bool) ($assessment['practice_enabled'] ?? false) && !(bool) ($assessment['practice'] ?? false)) { throw new InvalidArgumentException('Practice is not enabled for this assessment.'); }
        $active = $this->assessments->activeSession((int) $enrolment['id'], (int) $assessment['id'], $nodeId, $mode);
        if ($active !== null) { return $active; }
        if ($mode !== 'practice' && !$overview['can_start_graded']) { throw new InvalidArgumentException($diagnostic ? 'No diagnostic attempts remain.' : 'No graded assessment attempts remain.'); }
        $questions = $this->selectQuestions($assessment, $mode);
        if ($questions === []) { throw new InvalidArgumentException('No eligible questions are available for this assessment.'); }
        $session = $this->transactions->run(fn(): array => $this->assessments->createSession((int) $enrolment['id'], $assessment, $mode, $questions));
        $this->audit->record($userId, 'assessment.started', ['course_item_id' => (int) $assessment['id'], 'structure_node_id' => $nodeId, 'session_id' => (int) $session['id'], 'mode' => $mode, 'preview' => (bool) $enrolment['is_preview']]);
        return $session;
    }

    /** @return array<string,mixed> */
    public function session(int $userId, string $publicId): array
    {
        $session = $this->requireSession($userId, $publicId);
        if ($session['status'] === 'in_progress') { $this->commerceAccess?->assertAccess((int) $session['enrolment_id']); }
        if ($session['status'] === 'in_progress' && $this->remainingSeconds($session) <= 0) { return $this->finalise($session, true); }
        if ($session['status'] !== 'in_progress') { return $this->result($userId, $publicId); }
        $question = $this->assessments->nextQuestion((int) $session['id']);
        if ($question === null) { return $this->finalise($session, false); }
        $this->assessments->markPresented((int) $session['id'], (int) $question['question_id']);
        $session['question'] = $question; $session['counts'] = $this->assessments->sessionCounts((int) $session['id']); $session['remaining_seconds'] = $this->remainingSeconds($session); $session['timer_label'] = $this->timerLabel($session['remaining_seconds']);
        return $session;
    }

    /** @return array<string,mixed> */
    public function respond(int $userId, string $publicId, int $questionId, ?int $optionId, bool $skip): array
    {
        $session = $this->requireSession($userId, $publicId); $this->commerceAccess?->assertAccess((int) $session['enrolment_id']);
        if ($session['status'] !== 'in_progress') { return $this->result($userId, $publicId); }
        if ($this->remainingSeconds($session) <= 0) { return $this->finalise($session, true); }
        $current = $this->assessments->nextQuestion((int) $session['id']);
        if ($current === null || (int) $current['question_id'] !== $questionId) { throw new InvalidArgumentException('This is not the current assessment question.'); }
        $feedback = $this->transactions->run(fn(): array => $this->assessments->recordResponse((int) $session['id'], $questionId, $optionId, $skip, (bool) $session['negative_marking']));
        if (!$this->assessments->hasRemainingQuestions((int) $session['id'])) { return $this->finalise($session, false); }
        $next = $this->session($userId, $publicId); if ($session['attempt_mode'] === 'practice' && !$skip) { $next['feedback'] = $feedback; } return $next;
    }

    /** @return array<string,mixed> */
    public function finish(int $userId, string $publicId): array
    {
        $session = $this->requireSession($userId, $publicId); $this->commerceAccess?->assertAccess((int) $session['enrolment_id']);
        if ($session['status'] !== 'in_progress') { return $this->result($userId, $publicId); }
        return $this->finalise($session, $this->remainingSeconds($session) <= 0, true);
    }

    /** @return array<string,mixed> */
    public function result(int $userId, string $publicId): array
    {
        $session = $this->requireSession($userId, $publicId); $responses = $this->assessments->sessionResponses((int) $session['id']);
        foreach ($responses as &$response) { $response['has_response_seconds'] = $response['response_seconds'] !== null && $response['response_seconds'] !== ''; } unset($response);
        $session['responses'] = $responses; $session['counts'] = $this->assessments->sessionCounts((int) $session['id']); $session['elapsed_seconds'] = $this->elapsedSeconds($session); $session['elapsed_label'] = $this->timerLabel($session['elapsed_seconds']); $session['is_diagnostic'] = $session['assessment_type'] === 'diagnostic';
        if ($session['is_diagnostic']) { $passed = (float) ($session['percentage'] ?? 0) + .0001 >= (float) ($session['pass_mark'] ?? 50); $session['diagnostic_passed'] = $passed; $session['diagnostic_message_html'] = $passed ? (string) ($session['result_pass_html'] ?? '') : (string) ($session['result_fail_html'] ?? ''); $session['diagnostic_remediation_items'] = $this->remediationItems($responses, (int) $session['course_id']); }
        return $session;
    }

    /**
     * @param array<string,mixed> $assessment
     * @return list<array<string,mixed>>
     */
    private function selectQuestions(array $assessment, string $mode): array
    {
        $all = $this->itemRecords->questions((int) $assessment['id'], true); $poolMode = (string) ($assessment['practice_pool_mode'] ?? 'both');
        $eligible = array_values(array_filter($all, static function (array $question) use ($mode, $poolMode): bool { if ($mode === 'graded' || $mode === 'diagnostic') { return (bool) ($question['graded_eligible'] ?? true); } return match ($poolMode) { 'separate' => (bool) ($question['practice_eligible'] ?? true) && !(bool) ($question['graded_eligible'] ?? true), 'graded' => (bool) ($question['graded_eligible'] ?? true), default => (bool) ($question['practice_eligible'] ?? true) || (bool) ($question['graded_eligible'] ?? true) }; }));
        if ((bool) ($assessment['randomise_questions'] ?? true)) { shuffle($eligible); }
        $count = $mode === 'practice' ? (int) ($assessment['practice_question_count'] ?? 5) : (int) ($assessment['graded_question_count'] ?? count($eligible));
        return array_slice($eligible, 0, max(1, min($count, count($eligible))));
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    private function finalise(array $session, bool $timedOut, bool $manual = false): array
    {
        if ($session['status'] !== 'in_progress') { return $this->result((int) $session['user_id'], (string) $session['public_id']); }
        $result = $this->transactions->run(function () use ($session, $timedOut, $manual): array {
            if ($timedOut) { $this->assessments->markExpiredQuestions((int) $session['id']); } elseif ($manual) { $this->assessments->markRemainingUnanswered((int) $session['id']); }
            $calculated = $this->assessments->calculateSession((int) $session['id']);
            $diagnostic = $session['assessment_type'] === 'diagnostic'; $band = $diagnostic ? ['grade_code' => $calculated['percentage'] >= (float) $session['pass_mark'] ? 'Ready' : 'Review', 'grade_label' => 'Diagnostic', 'is_passing' => $calculated['percentage'] >= (float) $session['pass_mark']] : $this->band((float) $calculated['percentage'], $this->courses->gradeBands((int) $session['course_id']));
            $status = $timedOut ? 'timed_out' : 'submitted'; $this->assessments->completeSession((int) $session['id'], $status, $calculated, (string) $band['grade_code'], (bool) $band['is_passing']);
            return $calculated + ['grade_code' => $band['grade_code'], 'grade_label' => $band['grade_label'], 'passed' => $band['is_passing'], 'status' => $status];
        });
        $session = $this->requireSession((int) $session['user_id'], (string) $session['public_id']);
        if ($session['attempt_mode'] === 'graded' && empty($session['legacy_attempt_id'])) {
            $responses = []; foreach ($this->assessments->sessionResponses((int) $session['id']) as $response) { if ($response['selected_option_id'] !== null && $response['selected_option_id'] !== '') { $responses[] = ['question_id' => (int) $response['question_id'], 'selected_option_id' => (int) $response['selected_option_id'], 'is_correct' => (bool) $response['is_correct'], 'points_awarded' => (float) $response['points_awarded']]; } }
            $attemptId = $this->assessments->saveAttempt($session, $result, $responses); $this->assessments->attachLegacyAttempt((int) $session['id'], $attemptId);
            if (($session['assessment_role'] ?? '') === 'final') { $this->completeCourse($session); }
        }
        $this->audit->record((int) $session['user_id'], 'assessment.completed', ['course_item_id' => (int) $session['course_item_id'], 'structure_node_id' => (int) $session['structure_node_id'], 'session_id' => (int) $session['id'], 'mode' => $session['attempt_mode'], 'percentage' => $result['percentage'], 'status' => $result['status'], 'preview' => (bool) $session['is_preview']]);
        return $this->result((int) $session['user_id'], (string) $session['public_id']);
    }

    /** @param array<string,mixed> $session */
    private function completeCourse(array $session): void
    {
        $preceding = [];
        foreach ($this->itemRecords->structure((int) $session['course_id']) as $node) {
            $preceding[(int) $node['id']] = true;
            if ((int) $node['id'] === (int) $session['structure_node_id']) { break; }
        }
        $graded = []; $final = null;
        foreach ($this->itemRecords->assessmentContexts((int) $session['course_id']) as $context) {
            $nodeId = (int) $context['node_id'];
            if (!isset($preceding[$nodeId])) { continue; }
            $scores = $this->assessments->completedPercentages((int) $session['enrolment_id'], (int) $context['course_item_id'], $nodeId);
            if ($scores === []) { continue; }
            $value = $this->aggregate($scores, (string) $context['score_policy']);
            if ($context['assessment_role'] === 'final') { $final = $value; } else { $graded[] = $value; }
        }
        if ($final === null) { return; }
        $module = $graded === [] ? 0.0 : array_sum($graded) / count($graded);
        $overall = round($module * .5 + $final * .5, 2);
        $band = $this->band($overall, $this->courses->gradeBands((int) $session['course_id']));
        $before = $this->courses->enrolmentByIdForUser((int) $session['enrolment_id'], (int) $session['user_id']); $this->courses->saveCourseResult((int) $session['enrolment_id'], $module, $final, $overall, (string) $band['grade_code'], (bool) $band['is_passing']);
        if (($before['status'] ?? '') !== 'completed') { $this->audit->record((int) $session['user_id'], 'course.completed', ['course_id' => (int) $session['course_id'], 'enrolment_id' => (int) $session['enrolment_id'], 'completion_method' => 'final_assessment', 'percentage' => $overall, 'grade_code' => $band['grade_code'], 'passed' => $band['is_passing']]); }
        $course = $this->courses->findById((int) $session['course_id']); if ($course === null || !(bool) $course['certificate_enabled'] || !(bool) $band['is_passing']) { return; }
        $user = $this->courses->findUserById((int) $session['user_id']); $name = trim((string) ($user['certificate_name'] ?? $user['display_name'] ?? $user['email'] ?? 'Learner'));
        $this->courses->createCertificate((int) $session['enrolment_id'], $name, trim((string) ($course['certificate_title'] ?? '')) ?: 'Certificate of Completion', (string) $course['title'], (string) $band['grade_code'], $overall, (string) ($course['certificate_template'] ?? 'custom'), (string) ($course['certificate_body_text'] ?? 'has successfully completed'), (string) ($course['certificate_footer_text'] ?? ''), (string) ($course['certificate_signatory_name'] ?? ''), (string) ($course['certificate_signatory_title'] ?? ''), (string) ($course['certificate_template_html'] ?? ''), (string) ($course['certificate_template_css'] ?? ''));
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function courseAndEnrolment(int $userId, string $slug, bool $preview): array
    {
        $course = $this->courses->findBySlug(Slug::validate($slug)); if ($course === null) { throw new InvalidArgumentException('The course does not exist.'); }
        $enrolment = $this->courses->enrolment($userId, (int) $course['id'], $preview); if ($enrolment === null) { throw new InvalidArgumentException($preview ? 'Start a course preview first.' : 'This course is not in your library.'); }
        $this->commerceAccess?->assertAccess((int) $enrolment['id']); if (empty($enrolment['started_at'])) { throw new InvalidArgumentException('Click Start course before attempting an assessment.'); }
        return [$course, $enrolment];
    }

    /** @return array<string,mixed> */
    private function requireSession(int $userId, string $publicId): array
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $publicId) !== 1) { throw new InvalidArgumentException('Invalid assessment session.'); }
        $session = $this->assessments->sessionByPublicId($publicId); if ($session === null || (int) $session['user_id'] !== $userId) { throw new InvalidArgumentException('The assessment session does not exist.'); } return $session;
    }

    /**
     * @param list<array<string,mixed>> $responses
     * @return list<array<string,mixed>>
     */
    private function remediationItems(array $responses, int $courseId): array
    {
        $items = [];
        foreach ($responses as $response) {
            if (($response['is_correct'] ?? null) === true) { continue; }
            foreach ((array) ($response['remediation_item_keys'] ?? []) as $key) {
                $item = $this->itemRecords->itemByKey((string) $key);
                if ($item !== null) { $item['node_id'] = null; $items[(int) $item['id']] = $item; }
            }
        }
        foreach ($this->itemRecords->structure($courseId) as $node) {
            if ($node['node_type'] !== 'item') { continue; }
            foreach ($this->itemRecords->reachableItems((int) $node['course_item_id']) as $item) {
                if (isset($items[(int) $item['id']]) && $items[(int) $item['id']]['node_id'] === null) { $items[(int) $item['id']]['node_id'] = (int) $node['id']; }
            }
        }
        return array_values($items);
    }

    /** @param list<float> $values */
    private function aggregate(array $values, string $policy): float { if ($values === []) { return 0; } return match ($policy) { 'average' => array_sum($values) / count($values), 'latest' => $values[array_key_last($values)], default => max($values) }; }
    /**
     * @param list<array<string,mixed>> $bands
     * @return array<string,mixed>
     */
    private function band(float $percentage, array $bands): array { foreach ($bands as $band) { if ($percentage + .0001 >= (float) $band['minimum_percentage']) { return $band; } } return ['grade_code' => 'N/A', 'grade_label' => 'Not graded', 'is_passing' => false]; }
    /** @param array<string,mixed> $session */
    private function remainingSeconds(array $session): int { return max(0, (strtotime((string) $session['deadline_at']) ?: time()) - time()); }
    /** @param array<string,mixed> $session */
    private function elapsedSeconds(array $session): int { $start = strtotime((string) $session['started_at']) ?: time(); $end = strtotime((string) ($session['completed_at'] ?? '')) ?: time(); return max(0, $end - $start); }
    private function timerLabel(int $seconds): string { return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60); }
    private function durationLabel(int $seconds): string { $minutes = max(1, (int) ceil($seconds / 60)); if ($minutes < 60) { return $minutes . ' mins'; } $hours = intdiv($minutes, 60); $remaining = $minutes % 60; return $remaining === 0 ? $hours . ' hr' . ($hours === 1 ? '' : 's') : $hours . ' hr' . ($hours === 1 ? '' : 's') . ' ' . $remaining . ' mins'; }
}

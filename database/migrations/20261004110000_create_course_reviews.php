<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Course ratings and moderated learner reviews.
 *
 * One review per learner per course. A row holds two versions: the learner's current submission
 * (rating, comment, status, revision), which is what ADMIN moderates, and the published version
 * (published_rating, published_comment, published_at), which is the last one ADMIN approved and the
 * only one the public ever sees. Editing an approved review sends the new text to moderation while
 * the approved version stays published; ratings are aggregated from published versions only.
 *
 * Also adds the two permissions: LEARNING.REVIEW.CREATE for learners (STUDENT) and
 * COURSE.REVIEW.MANAGE for moderation (ADMIN, which holds every permission).
 */
final class CreateCourseReviews extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
INSERT INTO permissions (permission_key, permission_name, permission_group, permission_description) VALUES
    ('LEARNING.REVIEW.CREATE','CreateCourseReview','Learning','Rate and review a course the learner has studied.'),
    ('COURSE.REVIEW.MANAGE','ManageCourseReviews','Courses','Moderate learner course reviews.')
ON CONFLICT (permission_key) DO NOTHING;
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.permission_key IN ('LEARNING.REVIEW.CREATE','COURSE.REVIEW.MANAGE') WHERE r.role_key = 'ADMIN'
ON CONFLICT DO NOTHING;
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.permission_key = 'LEARNING.REVIEW.CREATE' WHERE r.role_key = 'STUDENT'
ON CONFLICT DO NOTHING;

CREATE TABLE course_reviews (
    id BIGSERIAL PRIMARY KEY,
    course_id BIGINT NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    -- The learner's current submission: what ADMIN moderates. Revision counts the learner's saves.
    rating SMALLINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    comment TEXT NOT NULL DEFAULT '' CHECK (char_length(comment) <= 2000),
    status VARCHAR(16) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected')),
    revision INTEGER NOT NULL DEFAULT 1 CHECK (revision > 0),
    -- The last approved version, the only one shown publicly or counted in ratings.
    published_rating SMALLINT NULL CHECK (published_rating BETWEEN 1 AND 5),
    published_comment TEXT NULL,
    published_at TIMESTAMPTZ NULL,
    submitted_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL,
    moderated_at TIMESTAMPTZ NULL,
    moderated_by_user_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    moderation_note TEXT NULL CHECK (char_length(moderation_note) <= 1000),
    -- Counts ADMIN decisions, so each one has its own analytics idempotency key.
    moderation_count INTEGER NOT NULL DEFAULT 0 CHECK (moderation_count >= 0),
    CONSTRAINT course_reviews_one_per_learner UNIQUE (course_id, user_id),
    CONSTRAINT course_reviews_published_whole CHECK ((published_rating IS NULL) = (published_at IS NULL) AND (published_rating IS NULL) = (published_comment IS NULL))
);
-- Public reviews of a course, newest first, and its rating aggregates.
CREATE INDEX course_reviews_published_idx ON course_reviews (course_id, published_at DESC) INCLUDE (published_rating) WHERE published_rating IS NOT NULL;
-- The moderation queue: by status, oldest submission first.
CREATE INDEX course_reviews_status_idx ON course_reviews (status, updated_at);
SQL);
    }

    public function down(): void
    {
        $this->execute(<<<'SQL'
DROP TABLE course_reviews;
DELETE FROM role_permissions WHERE permission_id IN (SELECT id FROM permissions WHERE permission_key IN ('LEARNING.REVIEW.CREATE','COURSE.REVIEW.MANAGE'));
DELETE FROM permissions WHERE permission_key IN ('LEARNING.REVIEW.CREATE','COURSE.REVIEW.MANAGE');
SQL);
    }
}

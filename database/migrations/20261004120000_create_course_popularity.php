<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The course popularity snapshot. A recalculation (CoursePopularityCalculator, run by
 * `bin/console popularity:recalculate`) writes one run and one row per eligible course in a single
 * transaction, replacing the previous run, so readers always see one complete ranking and never a
 * half-written one. Nothing is calculated from the raw event stream on a page request.
 *
 * The run keeps the window and every model parameter the ranking was calculated with; each row
 * keeps the source metrics and the points each component contributed, so ADMIN can see why a
 * course ranks where it does. Points are on a 0-100 scale and the components add up to the score.
 */
final class CreateCoursePopularity extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE course_popularity_runs (
    id BIGSERIAL PRIMARY KEY,
    calculated_at TIMESTAMPTZ NOT NULL,
    window_start TIMESTAMPTZ NOT NULL,
    window_end TIMESTAMPTZ NOT NULL,
    -- The weights, rating prior and confidence, smoothing and momentum periods used.
    parameters JSONB NOT NULL CHECK (jsonb_typeof(parameters) = 'object'),
    course_count INTEGER NOT NULL CHECK (course_count >= 0),
    CHECK (window_start < window_end)
);

CREATE TABLE course_popularity (
    course_id BIGINT PRIMARY KEY REFERENCES courses(id) ON DELETE CASCADE,
    run_id BIGINT NOT NULL REFERENCES course_popularity_runs(id) ON DELETE CASCADE,
    rank INTEGER NOT NULL CHECK (rank > 0),
    score NUMERIC(7,3) NOT NULL CHECK (score BETWEEN 0 AND 100),
    -- Points each component contributed; purchase points are after the refund penalty.
    view_points NUMERIC(7,3) NOT NULL CHECK (view_points >= 0),
    favourite_points NUMERIC(7,3) NOT NULL CHECK (favourite_points >= 0),
    purchase_points NUMERIC(7,3) NOT NULL CHECK (purchase_points >= 0),
    refund_penalty_points NUMERIC(7,3) NOT NULL CHECK (refund_penalty_points >= 0),
    rating_points NUMERIC(7,3) NOT NULL CHECK (rating_points >= 0),
    momentum_points NUMERIC(7,3) NOT NULL CHECK (momentum_points >= 0),
    -- Source metrics. Views are distinct viewers, not page hits.
    offer VARCHAR(8) NOT NULL CHECK (offer IN ('paid','free','none')),
    unique_views INTEGER NOT NULL CHECK (unique_views >= 0),
    learner_readers INTEGER NOT NULL CHECK (learner_readers >= 0),
    favourites INTEGER NOT NULL CHECK (favourites >= 0),
    purchases INTEGER NOT NULL CHECK (purchases >= 0),
    purchased_units INTEGER NOT NULL CHECK (purchased_units >= 0),
    refunds INTEGER NOT NULL CHECK (refunds >= 0),
    refunded_units NUMERIC(12,4) NOT NULL CHECK (refunded_units >= 0),
    refund_ratio NUMERIC(6,4) NOT NULL CHECK (refund_ratio BETWEEN 0 AND 1),
    approved_reviews INTEGER NOT NULL CHECK (approved_reviews >= 0),
    average_rating NUMERIC(4,3) NULL CHECK (average_rating BETWEEN 1 AND 5),
    weighted_rating NUMERIC(4,3) NULL CHECK (weighted_rating BETWEEN 1 AND 5),
    recent_views INTEGER NOT NULL CHECK (recent_views >= 0),
    previous_views INTEGER NOT NULL CHECK (previous_views >= 0),
    momentum_growth NUMERIC(10,4) NOT NULL CHECK (momentum_growth > 0)
);
-- Ranked listing and top N.
CREATE INDEX course_popularity_rank_idx ON course_popularity (score DESC, course_id);
CREATE INDEX course_popularity_run_idx ON course_popularity (run_id);
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE course_popularity; DROP TABLE course_popularity_runs');
    }
}

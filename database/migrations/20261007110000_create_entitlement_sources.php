<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Entitlement sources. A learner's access to a course can now come from several sources at once — an
 * individual purchase, one or more bundles, free access, or the enrolment's own non-commerce origin
 * (ADMIN assignment, company credit, seed) — each with its own access period, activation and expiry.
 *
 * - course_enrolments stays the one visible, effective access record per learner and course: the
 *   library, the reader and learning progress use it. Its expires_at is the projection of the
 *   latest expiry among the sources that are active.
 * - commerce_entitlements rows are the sources. The one-row-per-enrolment rule is removed; each row
 *   keeps its own access_period_seconds. A bundle line has one source per course and enrolment.
 * - source 'origin': an enrolment created outside commerce, whose own term is recorded as a source
 *   the first time a commerce source joins it, so neither replaces the other. It has no activation
 *   deadline: like the enrolment it came from, it starts when the learner starts.
 * - commerce_bundle_grants now always names the entitlement source the bundle line created for the
 *   course (entitlement_id) and records whether another source already granted the course then
 *   (shared_at_grant). A bundle never grants "nothing" for a course the learner already had.
 *
 * Grants recorded as already held before this change are fulfilled properly here: each gets the
 * bundle source it should have had, awaiting activation from when it was granted. The rollback
 * removes those sources and fails while an enrolment still has several sources.
 */
final class CreateEntitlementSources extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE commerce_entitlements ADD COLUMN access_period_seconds INTEGER NULL;
UPDATE commerce_entitlements e SET access_period_seconds = ce.access_period_seconds FROM course_enrolments ce WHERE ce.id = e.enrolment_id;
ALTER TABLE commerce_entitlements
    ALTER COLUMN access_period_seconds SET NOT NULL,
    ADD CONSTRAINT commerce_entitlements_period CHECK (access_period_seconds > 0),
    DROP CONSTRAINT commerce_entitlements_enrolment_id_key,
    ALTER COLUMN activation_deadline_at DROP NOT NULL,
    DROP CONSTRAINT commerce_entitlements_source_check,
    ADD CONSTRAINT commerce_entitlements_source_check CHECK (source IN ('paid_order','free','bundle','origin')),
    ADD CONSTRAINT commerce_entitlements_deadline CHECK ((activation_deadline_at IS NULL) = (source = 'origin')),
    ADD CONSTRAINT commerce_entitlements_origin CHECK (source <> 'origin' OR order_item_id IS NULL);
CREATE INDEX commerce_entitlements_enrolment_idx ON commerce_entitlements (enrolment_id);
CREATE UNIQUE INDEX commerce_entitlements_one_origin ON commerce_entitlements (enrolment_id) WHERE source = 'origin';
CREATE UNIQUE INDEX commerce_entitlements_bundle_source ON commerce_entitlements (order_item_id, enrolment_id) WHERE source = 'bundle';

-- The enrolments existing grants rest on that were created outside commerce get their origin source.
INSERT INTO commerce_entitlements(order_item_id,enrolment_id,state,source,snapshot,created_at,activation_deadline_at,access_started_at,access_expires_at,access_period_seconds)
SELECT NULL, ce.id,
       CASE WHEN ce.expires_at IS NULL THEN 'awaiting_activation' WHEN ce.expires_at <= now() THEN 'expired' ELSE 'active' END,
       'origin', jsonb_build_object('source_type', ce.source_type, 'source_reference', ce.source_reference), ce.assigned_at, NULL,
       CASE WHEN ce.expires_at IS NULL THEN NULL ELSE LEAST(COALESCE(ce.started_at, ce.assigned_at), ce.expires_at - INTERVAL '1 second') END,
       ce.expires_at, ce.access_period_seconds
  FROM course_enrolments ce
 WHERE ce.id IN (SELECT enrolment_id FROM commerce_bundle_grants WHERE outcome = 'already_held')
   AND NOT EXISTS (SELECT 1 FROM commerce_entitlements e WHERE e.enrolment_id = ce.id);
-- Each grant recorded as already held gets the bundle source it was owed.
INSERT INTO commerce_entitlements(order_item_id,enrolment_id,state,source,snapshot,created_at,activation_deadline_at,access_period_seconds)
SELECT g.order_item_id, g.enrolment_id, 'awaiting_activation', 'bundle',
       jsonb_build_object('course_id', g.course_id, 'bundle_id', i.bundle_id, 'order_item_id', i.id, 'access_period_seconds', i.access_period_seconds),
       g.created_at, g.created_at + make_interval(days => COALESCE((i.snapshot->>'activation_deadline_days')::int, 90)), i.access_period_seconds
  FROM commerce_bundle_grants g JOIN commerce_order_items i ON i.id = g.order_item_id
 WHERE g.outcome = 'already_held';

ALTER TABLE commerce_bundle_grants DISABLE TRIGGER commerce_bundle_grants_immutable;
ALTER TABLE commerce_bundle_grants
    ADD COLUMN entitlement_id BIGINT NULL REFERENCES commerce_entitlements(id) ON DELETE RESTRICT,
    ADD COLUMN shared_at_grant BOOLEAN NOT NULL DEFAULT FALSE;
UPDATE commerce_bundle_grants g SET shared_at_grant = (g.outcome = 'already_held'),
       entitlement_id = (SELECT e.id FROM commerce_entitlements e WHERE e.source = 'bundle' AND e.order_item_id = g.order_item_id AND e.enrolment_id = g.enrolment_id);
ALTER TABLE commerce_bundle_grants
    ALTER COLUMN entitlement_id SET NOT NULL,
    ADD CONSTRAINT commerce_bundle_grants_entitlement_key UNIQUE (entitlement_id),
    DROP COLUMN outcome;
ALTER TABLE commerce_bundle_grants ALTER COLUMN shared_at_grant DROP DEFAULT;
ALTER TABLE commerce_bundle_grants ENABLE TRIGGER commerce_bundle_grants_immutable;
SQL);
    }

    public function down(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE commerce_bundle_grants DISABLE TRIGGER commerce_bundle_grants_immutable;
ALTER TABLE commerce_bundle_grants ADD COLUMN outcome TEXT NULL CHECK (outcome IN ('granted','already_held'));
UPDATE commerce_bundle_grants SET outcome = CASE WHEN shared_at_grant THEN 'already_held' ELSE 'granted' END;
ALTER TABLE commerce_bundle_grants DROP CONSTRAINT commerce_bundle_grants_entitlement_id_fkey;
DELETE FROM commerce_entitlements WHERE id IN (SELECT entitlement_id FROM commerce_bundle_grants WHERE shared_at_grant) OR source = 'origin';
ALTER TABLE commerce_bundle_grants ALTER COLUMN outcome SET NOT NULL, DROP COLUMN entitlement_id, DROP COLUMN shared_at_grant;
ALTER TABLE commerce_bundle_grants ENABLE TRIGGER commerce_bundle_grants_immutable;
DROP INDEX commerce_entitlements_bundle_source;
DROP INDEX commerce_entitlements_one_origin;
DROP INDEX commerce_entitlements_enrolment_idx;
ALTER TABLE commerce_entitlements
    DROP CONSTRAINT commerce_entitlements_origin,
    DROP CONSTRAINT commerce_entitlements_deadline,
    DROP CONSTRAINT commerce_entitlements_source_check,
    ADD CONSTRAINT commerce_entitlements_source_check CHECK (source IN ('paid_order','free','bundle')),
    ALTER COLUMN activation_deadline_at SET NOT NULL,
    ADD CONSTRAINT commerce_entitlements_enrolment_id_key UNIQUE (enrolment_id),
    DROP CONSTRAINT commerce_entitlements_period,
    DROP COLUMN access_period_seconds;
SQL);
    }
}

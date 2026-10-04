<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The first-party analytics event stream: one append-only table for every business event, with
 * the entities it concerns as nullable references and anything specific to one event type in a
 * bounded JSONB object. The vocabulary of event types and sources is kept in
 * CattoLearning\Analytics; the database only checks their shape, so adding an event needs no
 * migration.
 *
 * References are SET NULL on delete: removing a person, course or order leaves the historical fact
 * standing but anonymous, which is also how retention will anonymise old events. A visitor is a
 * random per-browser-session UUID, never a session token, fingerprint or IP address.
 */
final class CreateAnalyticsEvents extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE analytics_events (
    id BIGSERIAL PRIMARY KEY,
    event_type VARCHAR(64) NOT NULL CHECK (event_type ~ '^[a-z][a-z0-9_]*$'),
    source VARCHAR(32) NOT NULL CHECK (source ~ '^[a-z][a-z0-9_]*$'),
    occurred_at TIMESTAMPTZ NOT NULL,
    user_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    visitor_id UUID NULL,
    course_id BIGINT NULL REFERENCES courses(id) ON DELETE SET NULL,
    course_item_id BIGINT NULL REFERENCES course_items(id) ON DELETE SET NULL,
    order_id BIGINT NULL REFERENCES commerce_orders(id) ON DELETE SET NULL,
    order_item_id BIGINT NULL REFERENCES commerce_order_items(id) ON DELETE SET NULL,
    metadata JSONB NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(metadata) = 'object'),
    -- One logical event per key: a replayed payment confirmation or refund cannot record twice.
    idempotency_key VARCHAR(160) NULL UNIQUE
);
-- Per course, per type, over a period: views, favourites, purchases and refunds of one course.
CREATE INDEX analytics_events_course_type_time_idx ON analytics_events (course_id, event_type, occurred_at) WHERE course_id IS NOT NULL;
-- Per type over a period, grouped by course or by day.
CREATE INDEX analytics_events_type_time_idx ON analytics_events (event_type, occurred_at);
-- Newest first for the ADMIN event list, and retention by age.
CREATE INDEX analytics_events_time_idx ON analytics_events (occurred_at);
-- Anonymising or removing one person's events.
CREATE INDEX analytics_events_user_idx ON analytics_events (user_id) WHERE user_id IS NOT NULL;
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE analytics_events');
    }
}

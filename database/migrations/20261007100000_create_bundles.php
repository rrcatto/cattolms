<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Course bundles: a catalogue and commerce offer that contains courses. A bundle is not a course.
 *
 * - bundles: the catalogue entity (slug, title, descriptions, cover SVG like a course's, status
 *   draft/published/retired and an optional sales window).
 * - bundle_courses: its ordered composition. Only courses can be members, so a bundle can never
 *   contain a bundle.
 * - bundle_offers: what is sold, one per bundle: price and the access period every contained course
 *   is granted for, and whether it is on sale now. Like a course price variant it is edited in place;
 *   an order keeps the price and composition it was placed with in its snapshot.
 * - commerce_cart_bundles: a cart's bundle lines, beside its course lines.
 * - commerce_order_items gains the product type 'bundle' (bundle_id, bundle_offer_id; no course or
 *   variant of its own). Bundles are sold to individuals only: never on a company order.
 * - commerce_entitlements gains the source 'bundle'. A bundle line grants one entitlement per
 *   contained course, so the one-entitlement-per-line rule now holds for the other sources only.
 * - commerce_bundle_grants: provenance, one append-only row per bundle line and contained course:
 *   the enrolment it granted, or the open enrolment the learner already held from another source.
 *   A refund revokes only access a bundle granted and no other live bundle line still covers.
 * - promotions: applies_to becomes two scopes, course_scope and bundle_scope (none, all or selected),
 *   with promotion_bundles beside promotion_courses. A course promotion never discounts a bundle
 *   because the bundle contains the course: the purchased product is the bundle.
 *
 * Adds BUNDLE.MANAGEMENT.VIEW and BUNDLE.MANAGE for ADMIN. The rollback fails while a bundle order exists.
 */
final class CreateBundles extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE bundles (
    id BIGSERIAL PRIMARY KEY,
    public_id UUID NOT NULL UNIQUE,
    slug VARCHAR(180) NOT NULL UNIQUE CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
    title VARCHAR(240) NOT NULL CHECK (btrim(title) <> ''),
    short_description VARCHAR(320) NOT NULL DEFAULT '',
    description_html TEXT NOT NULL DEFAULT '',
    cover_svg TEXT NOT NULL DEFAULT '',
    status VARCHAR(24) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','published','retired')),
    available_from TIMESTAMPTZ NULL,
    available_until TIMESTAMPTZ NULL,
    published_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    created_by_user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    updated_by_user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT bundles_window CHECK (available_from IS NULL OR available_until IS NULL OR available_until > available_from)
);
CREATE INDEX bundles_status_title_idx ON bundles (status, title, id);

CREATE TABLE bundle_courses (
    bundle_id BIGINT NOT NULL REFERENCES bundles(id) ON DELETE CASCADE,
    course_id BIGINT NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
    position INTEGER NOT NULL CHECK (position > 0),
    PRIMARY KEY (bundle_id, course_id),
    CONSTRAINT bundle_courses_position_key UNIQUE (bundle_id, position) DEFERRABLE INITIALLY DEFERRED
);
CREATE INDEX bundle_courses_course_idx ON bundle_courses (course_id);

CREATE TABLE bundle_offers (
    id BIGSERIAL PRIMARY KEY,
    public_id UUID NOT NULL UNIQUE,
    bundle_id BIGINT NOT NULL UNIQUE REFERENCES bundles(id) ON DELETE CASCADE,
    price_minor_units BIGINT NOT NULL CHECK (price_minor_units > 0),
    currency_code CHAR(3) NOT NULL DEFAULT 'ZAR' CHECK (currency_code ~ '^[A-Z]{3}$'),
    access_period_seconds INTEGER NOT NULL CHECK (access_period_seconds > 0),
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    created_by_user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    updated_by_user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT
);

CREATE TABLE commerce_cart_bundles (
    cart_id BIGINT NOT NULL REFERENCES commerce_carts(id) ON DELETE CASCADE,
    bundle_offer_id BIGINT NOT NULL REFERENCES bundle_offers(id) ON DELETE CASCADE,
    PRIMARY KEY (cart_id, bundle_offer_id)
);

ALTER TABLE commerce_order_items
    ALTER COLUMN variant_id DROP NOT NULL,
    ALTER COLUMN course_id DROP NOT NULL,
    ADD COLUMN bundle_id BIGINT NULL REFERENCES bundles(id) ON DELETE RESTRICT,
    ADD COLUMN bundle_offer_id BIGINT NULL REFERENCES bundle_offers(id) ON DELETE RESTRICT,
    DROP CONSTRAINT commerce_order_items_product_type_check,
    ADD CONSTRAINT commerce_order_items_product_type_check CHECK (product_type IN ('individual_access','company_credit','bundle')),
    DROP CONSTRAINT commerce_order_item_beneficiary,
    ADD CONSTRAINT commerce_order_item_beneficiary CHECK (
        (product_type = 'individual_access' AND beneficiary_user_id IS NOT NULL AND company_id IS NULL AND quantity = 1
            AND variant_id IS NOT NULL AND course_id IS NOT NULL AND bundle_id IS NULL AND bundle_offer_id IS NULL)
        OR (product_type = 'company_credit' AND beneficiary_user_id IS NULL AND company_id IS NOT NULL
            AND variant_id IS NOT NULL AND course_id IS NOT NULL AND bundle_id IS NULL AND bundle_offer_id IS NULL)
        OR (product_type = 'bundle' AND beneficiary_user_id IS NOT NULL AND company_id IS NULL AND quantity = 1
            AND variant_id IS NULL AND course_id IS NULL AND bundle_id IS NOT NULL AND bundle_offer_id IS NOT NULL));
CREATE UNIQUE INDEX commerce_order_bundle_idx ON commerce_order_items (order_id, bundle_id) WHERE product_type = 'bundle';
CREATE INDEX commerce_order_items_bundle_idx ON commerce_order_items (bundle_id, order_id) WHERE bundle_id IS NOT NULL;

CREATE OR REPLACE FUNCTION commerce_validate_order_item_channel() RETURNS trigger LANGUAGE plpgsql AS $function$
DECLARE order_company BIGINT;
BEGIN
    SELECT company_id INTO order_company FROM commerce_orders WHERE id=NEW.order_id;
    IF (NEW.product_type='company_credit' AND (order_company IS NULL OR order_company IS DISTINCT FROM NEW.company_id))
        OR (NEW.product_type IN ('individual_access','bundle') AND order_company IS NOT NULL) THEN
        RAISE EXCEPTION 'Order item beneficiary does not match the purchase channel';
    END IF;
    RETURN NEW;
END;
$function$;

ALTER TABLE commerce_entitlements
    DROP CONSTRAINT commerce_entitlements_order_item_id_key,
    DROP CONSTRAINT commerce_entitlements_source_check,
    ADD CONSTRAINT commerce_entitlements_source_check CHECK (source IN ('paid_order','free','bundle'));
CREATE UNIQUE INDEX commerce_entitlements_order_item_id_key ON commerce_entitlements (order_item_id) WHERE source <> 'bundle';

CREATE TABLE commerce_bundle_grants (
    order_item_id BIGINT NOT NULL REFERENCES commerce_order_items(id) ON DELETE RESTRICT,
    course_id BIGINT NOT NULL REFERENCES courses(id) ON DELETE RESTRICT,
    enrolment_id BIGINT NOT NULL REFERENCES course_enrolments(id) ON DELETE RESTRICT,
    -- granted: this line created the enrolment; already_held: the learner already had open access.
    outcome TEXT NOT NULL CHECK (outcome IN ('granted','already_held')),
    created_at TIMESTAMPTZ NOT NULL,
    PRIMARY KEY (order_item_id, course_id)
);
CREATE INDEX commerce_bundle_grants_enrolment_idx ON commerce_bundle_grants (enrolment_id);
CREATE TRIGGER commerce_bundle_grants_immutable BEFORE UPDATE OR DELETE ON commerce_bundle_grants
    FOR EACH ROW EXECUTE FUNCTION commerce_reject_history_mutation();

ALTER TABLE promotions
    ADD COLUMN course_scope TEXT NOT NULL DEFAULT 'all' CHECK (course_scope IN ('none','all','selected')),
    ADD COLUMN bundle_scope TEXT NOT NULL DEFAULT 'none' CHECK (bundle_scope IN ('none','all','selected')),
    ADD CONSTRAINT promotions_scope CHECK (course_scope <> 'none' OR bundle_scope <> 'none');
UPDATE promotions SET course_scope = CASE applies_to WHEN 'selected_courses' THEN 'selected' ELSE 'all' END;
ALTER TABLE promotions DROP COLUMN applies_to;
CREATE TABLE promotion_bundles (
    promotion_id BIGINT NOT NULL REFERENCES promotions(id) ON DELETE CASCADE,
    bundle_id BIGINT NOT NULL REFERENCES bundles(id) ON DELETE CASCADE,
    PRIMARY KEY (promotion_id, bundle_id)
);
CREATE INDEX promotion_bundles_bundle_idx ON promotion_bundles (bundle_id);

INSERT INTO permissions (permission_key, permission_name, permission_group, permission_description) VALUES
    ('BUNDLE.MANAGEMENT.VIEW','ViewBundleManagement','Bundles','View course bundles, their composition, offers and sales in Administration.'),
    ('BUNDLE.MANAGE','ManageBundles','Bundles','Create, compose, price, publish and retire course bundles, and delete unused drafts.')
ON CONFLICT (permission_key) DO NOTHING;
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.permission_key IN ('BUNDLE.MANAGEMENT.VIEW','BUNDLE.MANAGE') WHERE r.role_key = 'ADMIN'
ON CONFLICT DO NOTHING;
SQL);
    }

    public function down(): void
    {
        $this->execute(<<<'SQL'
DELETE FROM role_permissions WHERE permission_id IN (SELECT id FROM permissions WHERE permission_key IN ('BUNDLE.MANAGEMENT.VIEW','BUNDLE.MANAGE'));
DELETE FROM permissions WHERE permission_key IN ('BUNDLE.MANAGEMENT.VIEW','BUNDLE.MANAGE');
DROP TABLE promotion_bundles;
ALTER TABLE promotions ADD COLUMN applies_to TEXT NOT NULL DEFAULT 'all_courses' CHECK (applies_to IN ('all_courses','selected_courses'));
UPDATE promotions SET applies_to = CASE course_scope WHEN 'selected' THEN 'selected_courses' ELSE 'all_courses' END;
ALTER TABLE promotions DROP CONSTRAINT promotions_scope, DROP COLUMN bundle_scope, DROP COLUMN course_scope;
DROP TABLE commerce_bundle_grants;
DROP INDEX commerce_entitlements_order_item_id_key;
ALTER TABLE commerce_entitlements
    DROP CONSTRAINT commerce_entitlements_source_check,
    ADD CONSTRAINT commerce_entitlements_source_check CHECK (source IN ('paid_order','free')),
    ADD CONSTRAINT commerce_entitlements_order_item_id_key UNIQUE (order_item_id);
CREATE OR REPLACE FUNCTION commerce_validate_order_item_channel() RETURNS trigger LANGUAGE plpgsql AS $function$
DECLARE order_company BIGINT;
BEGIN
    SELECT company_id INTO order_company FROM commerce_orders WHERE id=NEW.order_id;
    IF (NEW.product_type='company_credit' AND (order_company IS NULL OR order_company IS DISTINCT FROM NEW.company_id))
        OR (NEW.product_type='individual_access' AND order_company IS NOT NULL) THEN
        RAISE EXCEPTION 'Order item beneficiary does not match the purchase channel';
    END IF;
    RETURN NEW;
END;
$function$;
DROP INDEX commerce_order_items_bundle_idx;
DROP INDEX commerce_order_bundle_idx;
ALTER TABLE commerce_order_items
    DROP CONSTRAINT commerce_order_item_beneficiary,
    ADD CONSTRAINT commerce_order_item_beneficiary CHECK (
        (product_type = 'individual_access' AND beneficiary_user_id IS NOT NULL AND company_id IS NULL AND quantity = 1)
        OR (product_type = 'company_credit' AND beneficiary_user_id IS NULL AND company_id IS NOT NULL)),
    DROP CONSTRAINT commerce_order_items_product_type_check,
    ADD CONSTRAINT commerce_order_items_product_type_check CHECK (product_type IN ('individual_access','company_credit')),
    DROP COLUMN bundle_offer_id,
    DROP COLUMN bundle_id,
    ALTER COLUMN course_id SET NOT NULL,
    ALTER COLUMN variant_id SET NOT NULL;
DROP TABLE commerce_cart_bundles;
DROP TABLE bundle_offers;
DROP TABLE bundle_courses;
DROP TABLE bundles;
SQL);
    }
}

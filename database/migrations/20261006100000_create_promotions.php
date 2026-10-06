<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Promotions: ADMIN-managed promo codes a purchaser enters at individual checkout.
 *
 * A promotion is mutable current data. A cart may carry one (commerce_carts.promotion_id); an order
 * records the one it was placed with, the discount it gave and how that discount was spread over its
 * lines, and those columns are immutable with the rest of the placed order. The order's snapshot keeps
 * everything needed to reproduce the calculation, so editing, deactivating or deleting a promotion
 * never changes an order or document. A promotion an order refers to cannot be deleted (RESTRICT);
 * it can only be deactivated.
 *
 * discount_value is basis points (1..10000 = 0.01%..100%) for a percentage and minor units of
 * `currency` for a fixed amount. currency is required exactly when an amount is involved: a fixed
 * discount or a minimum spend. Validity is the half-open window [starts_at, ends_at).
 *
 * applies_to names which order lines the promotion may discount. 'selected_courses' reads
 * promotion_courses; a later bundle phase adds its own value and table beside it.
 *
 * A use is reserved by placing an order (any state but cancelled counts against the limits) and
 * redeemed once the order is paid: promotion_redemptions holds one append-only row per paid order.
 *
 * Adds PLATFORM.PROMOTION.VIEW and PLATFORM.PROMOTION.MANAGE for ADMIN.
 */
final class CreatePromotions extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE promotions (
    id BIGSERIAL PRIMARY KEY,
    -- Canonical form only: upper case, so SAVE10, save10 and Save10 are one code.
    code VARCHAR(40) NOT NULL UNIQUE CHECK (code ~ '^[A-Z0-9][A-Z0-9_-]{2,39}$'),
    name VARCHAR(120) NOT NULL CHECK (btrim(name) <> ''),
    description VARCHAR(1000) NULL CHECK (description IS NULL OR btrim(description) <> ''),
    discount_type TEXT NOT NULL CHECK (discount_type IN ('percentage','fixed_amount')),
    discount_value BIGINT NOT NULL,
    currency CHAR(3) NULL CHECK (currency IS NULL OR currency ~ '^[A-Z]{3}$'),
    starts_at TIMESTAMPTZ NULL,
    ends_at TIMESTAMPTZ NULL,
    active BOOLEAN NOT NULL DEFAULT FALSE,
    minimum_order_minor BIGINT NULL CHECK (minimum_order_minor IS NULL OR minimum_order_minor > 0),
    maximum_total_uses INTEGER NULL CHECK (maximum_total_uses IS NULL OR maximum_total_uses > 0),
    maximum_uses_per_customer INTEGER NULL CHECK (maximum_uses_per_customer IS NULL OR maximum_uses_per_customer > 0),
    applies_to TEXT NOT NULL DEFAULT 'all_courses' CHECK (applies_to IN ('all_courses','selected_courses')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    created_by BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    updated_by BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT promotions_discount_value CHECK (
        (discount_type = 'percentage' AND discount_value BETWEEN 1 AND 10000)
        OR (discount_type = 'fixed_amount' AND discount_value > 0)),
    CONSTRAINT promotions_currency CHECK ((currency IS NOT NULL) = (discount_type = 'fixed_amount' OR minimum_order_minor IS NOT NULL)),
    CONSTRAINT promotions_window CHECK (starts_at IS NULL OR ends_at IS NULL OR ends_at > starts_at)
);
CREATE INDEX promotions_active_idx ON promotions (active, id DESC);

CREATE TABLE promotion_courses (
    promotion_id BIGINT NOT NULL REFERENCES promotions(id) ON DELETE CASCADE,
    course_id BIGINT NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
    PRIMARY KEY (promotion_id, course_id)
);
CREATE INDEX promotion_courses_course_idx ON promotion_courses (course_id);

-- The one promo code applied to an open cart. Deleting an unused promotion drops it from carts.
ALTER TABLE commerce_carts ADD COLUMN promotion_id BIGINT NULL REFERENCES promotions(id) ON DELETE SET NULL;

-- The promotion an order was placed with and the discount it gave. total_minor is the subtotal
-- less discount_minor; only a promotion can bring an order to zero.
ALTER TABLE commerce_orders
    ADD COLUMN promotion_id BIGINT NULL REFERENCES promotions(id) ON DELETE RESTRICT,
    ADD COLUMN discount_minor BIGINT NOT NULL DEFAULT 0 CHECK (discount_minor >= 0),
    ADD CONSTRAINT commerce_orders_promotion_discount CHECK ((promotion_id IS NULL) = (discount_minor = 0)),
    ADD CONSTRAINT commerce_orders_promotion_channel CHECK (promotion_id IS NULL OR cart_id IS NOT NULL),
    DROP CONSTRAINT commerce_orders_total_minor_check,
    ADD CONSTRAINT commerce_orders_total_minor_check CHECK (total_minor > 0 OR (total_minor = 0 AND promotion_id IS NOT NULL));
CREATE INDEX commerce_orders_promotion_idx ON commerce_orders (promotion_id, purchaser_user_id) WHERE promotion_id IS NOT NULL;

-- The share of the order's discount allocated to this line. amount_minor stays the line's price;
-- what the line was actually paid is amount_minor - discount_minor, and refunds are bounded by it.
ALTER TABLE commerce_order_items
    ADD COLUMN discount_minor BIGINT NOT NULL DEFAULT 0,
    ADD CONSTRAINT commerce_order_items_discount CHECK (discount_minor >= 0 AND discount_minor <= amount_minor);

CREATE TABLE promotion_redemptions (
    id BIGSERIAL PRIMARY KEY,
    promotion_id BIGINT NOT NULL REFERENCES promotions(id) ON DELETE RESTRICT,
    -- One redemption per paid order, however often its payment is confirmed or replayed.
    order_id BIGINT NOT NULL UNIQUE REFERENCES commerce_orders(id) ON DELETE RESTRICT,
    purchaser_user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    code VARCHAR(40) NOT NULL,
    discount_minor BIGINT NOT NULL CHECK (discount_minor > 0),
    currency CHAR(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    redeemed_at TIMESTAMPTZ NOT NULL
);
CREATE INDEX promotion_redemptions_promotion_idx ON promotion_redemptions (promotion_id, redeemed_at DESC);
CREATE TRIGGER promotion_redemptions_immutable BEFORE UPDATE OR DELETE ON promotion_redemptions
    FOR EACH ROW EXECUTE FUNCTION commerce_reject_history_mutation();

INSERT INTO permissions (permission_key, permission_name, permission_group, permission_description) VALUES
    ('PLATFORM.PROMOTION.VIEW','ViewPromotions','Commerce · Platform','View promotions, their promo codes and their usage when Commerce is installed.'),
    ('PLATFORM.PROMOTION.MANAGE','ManagePromotions','Commerce · Platform','Create, change, activate and deactivate promotions, and delete unused ones, when Commerce is installed.')
ON CONFLICT (permission_key) DO NOTHING;
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.permission_key IN ('PLATFORM.PROMOTION.VIEW','PLATFORM.PROMOTION.MANAGE') WHERE r.role_key = 'ADMIN'
ON CONFLICT DO NOTHING;
SQL);
    }

    public function down(): void
    {
        $this->execute(<<<'SQL'
DELETE FROM role_permissions WHERE permission_id IN (SELECT id FROM permissions WHERE permission_key IN ('PLATFORM.PROMOTION.VIEW','PLATFORM.PROMOTION.MANAGE'));
DELETE FROM permissions WHERE permission_key IN ('PLATFORM.PROMOTION.VIEW','PLATFORM.PROMOTION.MANAGE');
DROP TABLE promotion_redemptions;
ALTER TABLE commerce_order_items DROP CONSTRAINT commerce_order_items_discount, DROP COLUMN discount_minor;
DROP INDEX commerce_orders_promotion_idx;
ALTER TABLE commerce_orders
    DROP CONSTRAINT commerce_orders_total_minor_check,
    DROP CONSTRAINT commerce_orders_promotion_channel,
    DROP CONSTRAINT commerce_orders_promotion_discount,
    DROP COLUMN discount_minor,
    DROP COLUMN promotion_id,
    ADD CONSTRAINT commerce_orders_total_minor_check CHECK (total_minor > 0);
ALTER TABLE commerce_carts DROP COLUMN promotion_id;
DROP TABLE promotion_courses;
DROP TABLE promotions;
SQL);
    }
}

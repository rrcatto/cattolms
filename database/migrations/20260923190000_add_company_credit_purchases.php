<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Adds company purchase lines to the existing immutable order and credit-lot records. */
final class AddCompanyCreditPurchases extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE commerce_orders ALTER COLUMN cart_id DROP NOT NULL;
ALTER TABLE commerce_orders ADD COLUMN company_id BIGINT NULL REFERENCES companies(id) ON DELETE RESTRICT;
ALTER TABLE commerce_orders ADD COLUMN request_id BIGINT NULL REFERENCES course_requests(id) ON DELETE RESTRICT;
ALTER TABLE commerce_orders ADD COLUMN company_purchase_key UUID NULL UNIQUE;
ALTER TABLE commerce_orders ADD CONSTRAINT commerce_order_purchase_channel CHECK (
    (cart_id IS NOT NULL AND company_id IS NULL AND request_id IS NULL AND company_purchase_key IS NULL)
    OR (cart_id IS NULL AND company_id IS NOT NULL AND company_purchase_key IS NOT NULL)
);
CREATE INDEX commerce_orders_company_idx ON commerce_orders(company_id,id DESC) WHERE company_id IS NOT NULL;
CREATE INDEX commerce_orders_request_idx ON commerce_orders(request_id,state) WHERE request_id IS NOT NULL;

ALTER TABLE commerce_order_items ALTER COLUMN beneficiary_user_id DROP NOT NULL;
ALTER TABLE commerce_order_items ADD COLUMN company_id BIGINT NULL REFERENCES companies(id) ON DELETE RESTRICT;
ALTER TABLE commerce_order_items ADD COLUMN quantity INTEGER NOT NULL DEFAULT 1 CHECK (quantity > 0);
ALTER TABLE commerce_order_items ADD COLUMN product_type TEXT NOT NULL DEFAULT 'individual_access' CHECK (product_type IN ('individual_access','company_credit'));
ALTER TABLE commerce_order_items DROP CONSTRAINT commerce_order_items_order_id_course_id_key;
ALTER TABLE commerce_order_items ADD CONSTRAINT commerce_order_item_beneficiary CHECK (
    (product_type='individual_access' AND beneficiary_user_id IS NOT NULL AND company_id IS NULL AND quantity=1)
    OR (product_type='company_credit' AND beneficiary_user_id IS NULL AND company_id IS NOT NULL)
);
CREATE UNIQUE INDEX commerce_order_individual_course_idx ON commerce_order_items(order_id,course_id) WHERE product_type='individual_access';
CREATE UNIQUE INDEX commerce_order_company_variant_idx ON commerce_order_items(order_id,variant_id) WHERE product_type='company_credit';
CREATE INDEX commerce_order_items_company_idx ON commerce_order_items(company_id,order_id) WHERE company_id IS NOT NULL;

ALTER TABLE course_credits ADD COLUMN commerce_order_item_id BIGINT NULL UNIQUE REFERENCES commerce_order_items(id) ON DELETE RESTRICT;
ALTER TABLE course_credits ADD CONSTRAINT course_credit_purchase_origin CHECK (
    (source_type='purchase' AND commerce_order_item_id IS NOT NULL)
    OR (source_type<>'purchase' AND commerce_order_item_id IS NULL)
);
CREATE FUNCTION commerce_protect_purchased_credit_lot() RETURNS trigger LANGUAGE plpgsql AS $fn$
BEGIN
    IF OLD.commerce_order_item_id IS NOT NULL AND (
        TG_OP='DELETE' OR NEW.company_id IS DISTINCT FROM OLD.company_id
        OR NEW.course_id IS DISTINCT FROM OLD.course_id
        OR NEW.access_period_seconds IS DISTINCT FROM OLD.access_period_seconds
        OR NEW.quantity IS DISTINCT FROM OLD.quantity
        OR NEW.commerce_order_item_id IS DISTINCT FROM OLD.commerce_order_item_id
        OR NEW.source_type IS DISTINCT FROM OLD.source_type
    ) THEN
        RAISE EXCEPTION 'Purchased credit lot terms cannot be changed';
    END IF;
    IF TG_OP='DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$fn$;
CREATE TRIGGER commerce_purchased_credit_lot_immutable BEFORE UPDATE OR DELETE ON course_credits FOR EACH ROW EXECUTE FUNCTION commerce_protect_purchased_credit_lot();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Company purchase history cannot be removed by rollback.');
    }
}

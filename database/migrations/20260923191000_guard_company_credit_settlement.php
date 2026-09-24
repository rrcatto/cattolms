<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Keeps one active purchase per request and prevents credit lots before paid settlement. */
final class GuardCompanyCreditSettlement extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE UNIQUE INDEX commerce_one_active_company_order_per_request ON commerce_orders(request_id)
WHERE request_id IS NOT NULL AND state IN ('placed','awaiting_payment','manual_review','paid','fulfilled');

CREATE FUNCTION commerce_validate_order_item_channel() RETURNS trigger LANGUAGE plpgsql AS $fn$
DECLARE order_company BIGINT;
BEGIN
    SELECT company_id INTO order_company FROM commerce_orders WHERE id=NEW.order_id;
    IF (NEW.product_type='company_credit' AND (order_company IS NULL OR order_company IS DISTINCT FROM NEW.company_id))
        OR (NEW.product_type='individual_access' AND order_company IS NOT NULL) THEN
        RAISE EXCEPTION 'Order item beneficiary does not match the purchase channel';
    END IF;
    RETURN NEW;
END;
$fn$;
CREATE TRIGGER commerce_order_item_channel_guard BEFORE INSERT ON commerce_order_items
FOR EACH ROW EXECUTE FUNCTION commerce_validate_order_item_channel();

CREATE FUNCTION commerce_validate_purchased_credit() RETURNS trigger LANGUAGE plpgsql AS $fn$
DECLARE purchased RECORD;
BEGIN
    IF NEW.source_type <> 'purchase' THEN RETURN NEW; END IF;
    SELECT i.product_type,i.company_id,i.course_id,i.access_period_seconds,i.quantity,o.state
      INTO purchased FROM commerce_order_items i JOIN commerce_orders o ON o.id=i.order_id
     WHERE i.id=NEW.commerce_order_item_id;
    IF NOT FOUND OR purchased.product_type <> 'company_credit' OR purchased.state NOT IN ('paid','fulfilled')
        OR purchased.company_id IS DISTINCT FROM NEW.company_id
        OR purchased.course_id IS DISTINCT FROM NEW.course_id
        OR purchased.access_period_seconds IS DISTINCT FROM NEW.access_period_seconds
        OR purchased.quantity IS DISTINCT FROM NEW.quantity THEN
        RAISE EXCEPTION 'A purchased credit requires a matching fully paid company order item';
    END IF;
    RETURN NEW;
END;
$fn$;
CREATE TRIGGER commerce_purchased_credit_settlement_guard BEFORE INSERT ON course_credits
FOR EACH ROW EXECUTE FUNCTION commerce_validate_purchased_credit();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Company purchase history cannot be removed by rollback.');
    }
}

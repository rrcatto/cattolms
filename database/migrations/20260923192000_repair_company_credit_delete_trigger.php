<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Preserve ordinary credit cleanup while keeping paid credit lots immutable. */
final class RepairCompanyCreditDeleteTrigger extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE OR REPLACE FUNCTION commerce_protect_purchased_credit_lot() RETURNS trigger LANGUAGE plpgsql AS $fn$
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
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('This correction must remain in place.');
    }
}

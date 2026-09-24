<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Serializes credit allocation with refund decisions so a refunded unit cannot be reused. */
final class GuardRefundedCreditAllocations extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE FUNCTION commerce_guard_refunded_credit_allocation() RETURNS trigger LANGUAGE plpgsql AS $fn$
DECLARE lot RECORD;
DECLARE used_units BIGINT;
BEGIN
    IF NEW.status NOT IN ('assigned','consumed') THEN RETURN NEW; END IF;
    SELECT quantity,refunded_quantity INTO lot FROM course_credits WHERE id=NEW.credit_id FOR UPDATE;
    SELECT COUNT(*) INTO used_units FROM course_credit_allocations
     WHERE credit_id=NEW.credit_id AND status IN ('assigned','consumed')
       AND (TG_OP='INSERT' OR id<>NEW.id);
    IF used_units >= lot.quantity-lot.refunded_quantity THEN
        RAISE EXCEPTION 'No unrefunded credit units remain for allocation';
    END IF;
    RETURN NEW;
END;
$fn$;
CREATE TRIGGER commerce_refunded_credit_allocation_guard BEFORE INSERT OR UPDATE OF credit_id,status ON course_credit_allocations
FOR EACH ROW EXECUTE FUNCTION commerce_guard_refunded_credit_allocation();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Refunded credit allocation protection cannot be rolled back.');
    }
}

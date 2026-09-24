<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Evidence-backed manual settlement and append-only refund/fund history. */
final class AddPaymentAdministrationAndRefunds extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
INSERT INTO commerce_document_numbers(kind,next_number) VALUES ('credit_note',1);
ALTER TABLE commerce_documents DROP CONSTRAINT commerce_documents_kind_check;
ALTER TABLE commerce_documents ADD CONSTRAINT commerce_documents_kind_check CHECK (kind IN ('invoice','receipt','credit_note'));

CREATE TABLE commerce_manual_payment_evidence (
    id BIGSERIAL PRIMARY KEY,
    payment_id UUID NOT NULL UNIQUE REFERENCES commerce_payments(id) ON DELETE RESTRICT,
    order_id BIGINT NOT NULL REFERENCES commerce_orders(id) ON DELETE RESTRICT,
    amount_minor BIGINT NOT NULL CHECK (amount_minor>0),
    currency CHAR(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    received_at TIMESTAMPTZ NOT NULL,
    bank_reference TEXT NOT NULL CHECK (length(trim(bank_reference)) BETWEEN 3 AND 200),
    reason TEXT NOT NULL CHECK (length(trim(reason)) BETWEEN 5 AND 2000),
    actor_user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    confirmed_at TIMESTAMPTZ NOT NULL
);
CREATE UNIQUE INDEX commerce_manual_bank_reference ON commerce_manual_payment_evidence(bank_reference);
CREATE TRIGGER commerce_manual_evidence_immutable BEFORE UPDATE OR DELETE ON commerce_manual_payment_evidence FOR EACH ROW EXECUTE FUNCTION commerce_reject_history_mutation();

CREATE TABLE commerce_refunds (
    id BIGSERIAL PRIMARY KEY,
    public_id UUID NOT NULL UNIQUE,
    request_key UUID NOT NULL UNIQUE,
    order_id BIGINT NOT NULL REFERENCES commerce_orders(id) ON DELETE RESTRICT,
    order_item_id BIGINT NOT NULL REFERENCES commerce_order_items(id) ON DELETE RESTRICT,
    quantity INTEGER NOT NULL CHECK (quantity>0),
    amount_minor BIGINT NOT NULL CHECK (amount_minor>0),
    currency CHAR(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    basis TEXT NOT NULL CHECK (basis IN ('statutory','service_failure','goodwill','voluntary')),
    reason TEXT NOT NULL CHECK (length(trim(reason)) BETWEEN 5 AND 2000),
    approved_by_user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    approved_at TIMESTAMPTZ NOT NULL
);
CREATE INDEX commerce_refunds_order_item ON commerce_refunds(order_item_id,id);
CREATE TRIGGER commerce_refund_immutable BEFORE UPDATE OR DELETE ON commerce_refunds FOR EACH ROW EXECUTE FUNCTION commerce_reject_history_mutation();

CREATE TABLE commerce_fund_accounts (
    id BIGSERIAL PRIMARY KEY,
    owner_user_id BIGINT NULL REFERENCES users(id) ON DELETE RESTRICT,
    owner_company_id BIGINT NULL REFERENCES companies(id) ON DELETE RESTRICT,
    currency CHAR(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CHECK ((owner_user_id IS NULL) <> (owner_company_id IS NULL))
);
CREATE UNIQUE INDEX commerce_fund_user_currency ON commerce_fund_accounts(owner_user_id,currency) WHERE owner_user_id IS NOT NULL;
CREATE UNIQUE INDEX commerce_fund_company_currency ON commerce_fund_accounts(owner_company_id,currency) WHERE owner_company_id IS NOT NULL;
CREATE TABLE commerce_fund_entries (
    id BIGSERIAL PRIMARY KEY,
    account_id BIGINT NOT NULL REFERENCES commerce_fund_accounts(id) ON DELETE RESTRICT,
    refund_id BIGINT NOT NULL UNIQUE REFERENCES commerce_refunds(id) ON DELETE RESTRICT,
    amount_minor BIGINT NOT NULL CHECK (amount_minor>0),
    created_at TIMESTAMPTZ NOT NULL
);
CREATE TRIGGER commerce_fund_entry_immutable BEFORE UPDATE OR DELETE ON commerce_fund_entries FOR EACH ROW EXECUTE FUNCTION commerce_reject_history_mutation();

ALTER TABLE course_credits ADD COLUMN refunded_quantity INTEGER NOT NULL DEFAULT 0;
ALTER TABLE course_credits ADD CONSTRAINT course_credit_refunded_quantity CHECK (refunded_quantity>=0 AND refunded_quantity<=quantity);
CREATE FUNCTION commerce_guard_credit_refund_projection() RETURNS trigger LANGUAGE plpgsql AS $fn$
BEGIN
    IF NEW.refunded_quantity IS DISTINCT FROM OLD.refunded_quantity THEN
        IF OLD.commerce_order_item_id IS NULL THEN RAISE EXCEPTION 'Only purchased credit lots can be refunded'; END IF;
        IF NEW.refunded_quantity < OLD.refunded_quantity THEN RAISE EXCEPTION 'Credit refunds cannot be reversed by editing a lot'; END IF;
        IF NEW.refunded_quantity + (SELECT COUNT(*) FROM course_credit_allocations a WHERE a.credit_id=OLD.id AND a.status IN ('assigned','consumed')) > OLD.quantity THEN
            RAISE EXCEPTION 'Allocated credit units cannot be refunded';
        END IF;
    END IF;
    RETURN NEW;
END;
$fn$;
CREATE TRIGGER commerce_credit_refund_projection_guard BEFORE UPDATE ON course_credits FOR EACH ROW EXECUTE FUNCTION commerce_guard_credit_refund_projection();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Financial history cannot be removed by rollback.');
    }
}

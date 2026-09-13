<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Adds commercial records without changing the historical LMS baseline. */
final class AddCommerceFoundation extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE commerce_carts (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    revision INTEGER NOT NULL DEFAULT 0,
    state TEXT NOT NULL DEFAULT 'open' CHECK (state IN ('open','placed')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE UNIQUE INDEX commerce_cart_open ON commerce_carts(user_id) WHERE state='open';
CREATE TABLE commerce_cart_items (
    cart_id BIGINT NOT NULL REFERENCES commerce_carts(id) ON DELETE CASCADE,
    variant_id BIGINT NOT NULL REFERENCES course_price_variants(id) ON DELETE RESTRICT,
    PRIMARY KEY (cart_id,variant_id)
);
CREATE TABLE commerce_orders (
    id BIGSERIAL PRIMARY KEY,
    public_id UUID NOT NULL UNIQUE,
    purchaser_user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    cart_id BIGINT NOT NULL UNIQUE REFERENCES commerce_carts(id) ON DELETE RESTRICT,
    state TEXT NOT NULL CHECK (state IN ('placed','awaiting_payment','manual_review','paid','fulfilled','cancelled','partially_refunded','refunded')),
    total_minor BIGINT NOT NULL CHECK (total_minor > 0),
    currency CHAR(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    snapshot JSONB NOT NULL,
    placed_at TIMESTAMPTZ NOT NULL,
    payment_due_at TIMESTAMPTZ NOT NULL,
    CHECK (payment_due_at > placed_at)
);
CREATE INDEX commerce_order_purchaser ON commerce_orders(purchaser_user_id,id DESC);
CREATE INDEX commerce_order_due ON commerce_orders(payment_due_at) WHERE state='awaiting_payment';
CREATE TABLE commerce_order_items (
    id BIGSERIAL PRIMARY KEY,
    order_id BIGINT NOT NULL REFERENCES commerce_orders(id) ON DELETE RESTRICT,
    variant_id BIGINT NOT NULL REFERENCES course_price_variants(id) ON DELETE RESTRICT,
    course_id BIGINT NOT NULL REFERENCES courses(id) ON DELETE RESTRICT,
    beneficiary_user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    amount_minor BIGINT NOT NULL CHECK (amount_minor > 0),
    access_period_seconds INTEGER NOT NULL CHECK (access_period_seconds > 0),
    snapshot JSONB NOT NULL,
    UNIQUE(order_id,course_id)
);
CREATE TABLE commerce_document_numbers (kind TEXT PRIMARY KEY, next_number BIGINT NOT NULL CHECK(next_number>0));
INSERT INTO commerce_document_numbers VALUES ('invoice',1),('receipt',1);
CREATE TABLE commerce_documents (
    id BIGSERIAL PRIMARY KEY,
    public_id UUID NOT NULL UNIQUE,
    order_id BIGINT NOT NULL REFERENCES commerce_orders(id) ON DELETE RESTRICT,
    kind TEXT NOT NULL CHECK(kind IN ('invoice','receipt')),
    number TEXT NOT NULL UNIQUE,
    source_key TEXT NOT NULL UNIQUE,
    snapshot JSONB NOT NULL,
    issued_at TIMESTAMPTZ NOT NULL
);
CREATE UNIQUE INDEX commerce_one_invoice ON commerce_documents(order_id) WHERE kind='invoice';
CREATE TABLE commerce_payments (
    id UUID PRIMARY KEY,
    order_id BIGINT NOT NULL REFERENCES commerce_orders(id) ON DELETE RESTRICT,
    request_key UUID NOT NULL,
    gateway TEXT NOT NULL,
    state TEXT NOT NULL CHECK(state IN ('created','pending','paid','failed','cancelled','disputed','reversed','partially_refunded','refunded')),
    amount_minor BIGINT NOT NULL CHECK(amount_minor>0),
    currency CHAR(3) NOT NULL,
    provider_reference TEXT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    UNIQUE(order_id,request_key)
);
CREATE UNIQUE INDEX commerce_payment_in_flight ON commerce_payments(order_id) WHERE state IN ('created','pending');
CREATE UNIQUE INDEX commerce_payment_reference ON commerce_payments(gateway,provider_reference) WHERE provider_reference IS NOT NULL;
CREATE TABLE commerce_payment_events (
    id BIGSERIAL PRIMARY KEY,
    payment_id UUID NOT NULL REFERENCES commerce_payments(id) ON DELETE RESTRICT,
    event_key TEXT NOT NULL UNIQUE,
    state TEXT NOT NULL,
    payload JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL
);
CREATE TABLE commerce_audit_events (
    id BIGSERIAL PRIMARY KEY,
    order_id BIGINT NULL REFERENCES commerce_orders(id) ON DELETE RESTRICT,
    actor_user_id BIGINT NULL REFERENCES users(id) ON DELETE RESTRICT,
    event TEXT NOT NULL,
    payload JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL
);
CREATE TABLE commerce_entitlements (
    id BIGSERIAL PRIMARY KEY,
    order_item_id BIGINT NULL UNIQUE REFERENCES commerce_order_items(id) ON DELETE RESTRICT,
    enrolment_id BIGINT NOT NULL UNIQUE REFERENCES course_enrolments(id) ON DELETE RESTRICT,
    state TEXT NOT NULL CHECK(state IN ('awaiting_activation','active','suspended','expired','revoked')),
    source TEXT NOT NULL CHECK(source IN ('paid_order','free')),
    snapshot JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    activation_deadline_at TIMESTAMPTZ NOT NULL,
    access_started_at TIMESTAMPTZ NULL,
    access_expires_at TIMESTAMPTZ NULL,
    CHECK ((access_started_at IS NULL) = (access_expires_at IS NULL)),
    CHECK (access_expires_at IS NULL OR access_expires_at > access_started_at)
);
CREATE INDEX commerce_entitlement_activation ON commerce_entitlements(activation_deadline_at) WHERE state='awaiting_activation';
CREATE INDEX commerce_entitlement_expiry ON commerce_entitlements(access_expires_at) WHERE state='active';
CREATE TABLE commerce_outbox (
    id BIGSERIAL PRIMARY KEY,
    event_key TEXT NOT NULL UNIQUE,
    event TEXT NOT NULL,
    payload JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    delivered_at TIMESTAMPTZ NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    last_error TEXT NULL
);
CREATE FUNCTION commerce_reject_history_mutation() RETURNS trigger LANGUAGE plpgsql AS $fn$
BEGIN
    RAISE EXCEPTION 'Commerce history is immutable; append a correcting record';
END;
$fn$;
CREATE TRIGGER commerce_item_immutable BEFORE UPDATE OR DELETE ON commerce_order_items FOR EACH ROW EXECUTE FUNCTION commerce_reject_history_mutation();
CREATE TRIGGER commerce_document_immutable BEFORE UPDATE OR DELETE ON commerce_documents FOR EACH ROW EXECUTE FUNCTION commerce_reject_history_mutation();
CREATE TRIGGER commerce_payment_event_immutable BEFORE UPDATE OR DELETE ON commerce_payment_events FOR EACH ROW EXECUTE FUNCTION commerce_reject_history_mutation();
CREATE TRIGGER commerce_audit_immutable BEFORE UPDATE OR DELETE ON commerce_audit_events FOR EACH ROW EXECUTE FUNCTION commerce_reject_history_mutation();
CREATE FUNCTION commerce_protect_order_snapshot() RETURNS trigger LANGUAGE plpgsql AS $fn$
BEGIN
    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Orders cannot be deleted'; END IF;
    IF (to_jsonb(NEW)-'state') IS DISTINCT FROM (to_jsonb(OLD)-'state') THEN
        RAISE EXCEPTION 'Placed order terms cannot be changed';
    END IF;
    RETURN NEW;
END;
$fn$;
CREATE TRIGGER commerce_order_snapshot_immutable BEFORE UPDATE OR DELETE ON commerce_orders FOR EACH ROW EXECUTE FUNCTION commerce_protect_order_snapshot();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Commerce financial history cannot be removed by rollback.');
    }
}

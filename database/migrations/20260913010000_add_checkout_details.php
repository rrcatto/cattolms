<?php

declare(strict_types=1);
use Phinx\Migration\AbstractMigration;

/** Stores reusable billing details and mutable payment choices separately from immutable invoices. */
final class AddCheckoutDetails extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE users ADD COLUMN billing_address TEXT NOT NULL DEFAULT '';
CREATE TABLE commerce_order_preferences (
    order_id BIGINT PRIMARY KEY REFERENCES commerce_orders(id) ON DELETE RESTRICT,
    payment_method TEXT NOT NULL CHECK(payment_method IN ('dummy','eft'))
);
CREATE TABLE commerce_document_files (
    document_id BIGINT PRIMARY KEY REFERENCES commerce_documents(id) ON DELETE RESTRICT,
    pdf_base64 TEXT NOT NULL
);
SQL);
    }
    public function down(): void { throw new RuntimeException('Financial records must be retained.'); }
}

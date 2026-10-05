<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Reusable billing profiles, separate from the personal profile and from orders.
 *
 * One current profile per owner, with explicit ownership rather than a polymorphic owner column:
 * user_billing_profiles is keyed by the person, company_billing_profiles by the company. Both are
 * mutable current data. An order copies the profile into its immutable snapshot when it is placed,
 * so deleting or changing a profile never touches an issued order or document; the profiles cascade
 * away with their owner.
 *
 * users.billing_address, the single free-text field the profile replaces, is removed.
 *
 * Adds COMPANY.BILLING.MANAGE, editing the company's billing details, granted to COMPANY_ADMIN
 * (and ADMIN, which holds every permission).
 */
final class CreateBillingProfiles extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE user_billing_profiles (
    user_id BIGINT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    billing_name VARCHAR(200) NOT NULL CHECK (btrim(billing_name) <> ''),
    -- A business or trading name an individual bills to, without becoming a company account.
    organisation_name VARCHAR(200) NULL CHECK (organisation_name IS NULL OR btrim(organisation_name) <> ''),
    -- Billing identity only: printed when present, never used to calculate tax.
    tax_registration_number VARCHAR(40) NULL CHECK (tax_registration_number IS NULL OR btrim(tax_registration_number) <> ''),
    address_line_1 VARCHAR(200) NOT NULL CHECK (btrim(address_line_1) <> ''),
    address_line_2 VARCHAR(200) NULL CHECK (address_line_2 IS NULL OR btrim(address_line_2) <> ''),
    locality VARCHAR(120) NULL CHECK (locality IS NULL OR btrim(locality) <> ''),
    city VARCHAR(120) NOT NULL CHECK (btrim(city) <> ''),
    region VARCHAR(120) NULL CHECK (region IS NULL OR btrim(region) <> ''),
    postal_code VARCHAR(20) NULL CHECK (postal_code IS NULL OR btrim(postal_code) <> ''),
    -- ISO 3166-1 alpha-2; there is deliberately no countries table yet (ROADMAP 2d).
    country_code CHAR(2) NOT NULL CHECK (country_code ~ '^[A-Z]{2}$'),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE company_billing_profiles (
    company_id BIGINT PRIMARY KEY REFERENCES companies(id) ON DELETE CASCADE,
    -- The company's legal billing identity; it may differ from the company's display name.
    billing_name VARCHAR(200) NOT NULL CHECK (btrim(billing_name) <> ''),
    tax_registration_number VARCHAR(40) NULL CHECK (tax_registration_number IS NULL OR btrim(tax_registration_number) <> ''),
    address_line_1 VARCHAR(200) NOT NULL CHECK (btrim(address_line_1) <> ''),
    address_line_2 VARCHAR(200) NULL CHECK (address_line_2 IS NULL OR btrim(address_line_2) <> ''),
    locality VARCHAR(120) NULL CHECK (locality IS NULL OR btrim(locality) <> ''),
    city VARCHAR(120) NOT NULL CHECK (btrim(city) <> ''),
    region VARCHAR(120) NULL CHECK (region IS NULL OR btrim(region) <> ''),
    postal_code VARCHAR(20) NULL CHECK (postal_code IS NULL OR btrim(postal_code) <> ''),
    country_code CHAR(2) NOT NULL CHECK (country_code ~ '^[A-Z]{2}$'),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

ALTER TABLE users DROP COLUMN billing_address;

INSERT INTO permissions (permission_key, permission_name, permission_group, permission_description) VALUES
    ('COMPANY.BILLING.MANAGE','ManageCompanyBilling','Company','View and change the billing details the current company is invoiced to.')
ON CONFLICT (permission_key) DO NOTHING;
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.permission_key = 'COMPANY.BILLING.MANAGE' WHERE r.role_key IN ('ADMIN','COMPANY_ADMIN')
ON CONFLICT DO NOTHING;
SQL);
    }

    public function down(): void
    {
        $this->execute(<<<'SQL'
DELETE FROM role_permissions WHERE permission_id IN (SELECT id FROM permissions WHERE permission_key = 'COMPANY.BILLING.MANAGE');
DELETE FROM permissions WHERE permission_key = 'COMPANY.BILLING.MANAGE';
ALTER TABLE users ADD COLUMN billing_address TEXT NOT NULL DEFAULT '';
DROP TABLE company_billing_profiles;
DROP TABLE user_billing_profiles;
SQL);
    }
}

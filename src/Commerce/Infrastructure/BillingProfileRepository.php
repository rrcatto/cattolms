<?php

declare(strict_types=1);

namespace CattoLearning\Commerce\Infrastructure;

use CattoLearning\Commerce\Domain\BillingDetails;
use CattoLearning\Infrastructure\Persistence\Database;

/**
 * The current reusable billing profiles: one row per person in user_billing_profiles and one per
 * company in company_billing_profiles, each keyed by its owner. Both are mutable current data;
 * nothing that renders an issued order or document reads them.
 */
final class BillingProfileRepository
{
    private const COLUMNS = 'billing_name, organisation_name, tax_registration_number, address_line_1, address_line_2, locality, city, region, postal_code, country_code';
    private const COMPANY_COLUMNS = 'billing_name, tax_registration_number, address_line_1, address_line_2, locality, city, region, postal_code, country_code';

    public function __construct(private readonly Database $db)
    {
    }

    public function forUser(int $userId): ?BillingDetails
    {
        $row = $this->db->fetchAssociative('SELECT ' . self::COLUMNS . ' FROM user_billing_profiles WHERE user_id = :id', ['id' => $userId]);
        return $row === false ? null : BillingDetails::fromRow($row, true);
    }

    public function forCompany(int $companyId): ?BillingDetails
    {
        $row = $this->db->fetchAssociative('SELECT ' . self::COMPANY_COLUMNS . ' FROM company_billing_profiles WHERE company_id = :id', ['id' => $companyId]);
        return $row === false ? null : BillingDetails::fromRow($row, false);
    }

    public function saveForUser(int $userId, BillingDetails $details, string $now): void
    {
        $this->db->executeStatement(
            'INSERT INTO user_billing_profiles (user_id, ' . self::COLUMNS . ', created_at, updated_at)
             VALUES (:owner, :billing_name, :organisation_name, :tax_registration_number, :address_line_1, :address_line_2, :locality, :city, :region, :postal_code, :country_code, :now, :now)
             ON CONFLICT (user_id) DO UPDATE SET ' . self::assignments(self::COLUMNS) . ', updated_at = EXCLUDED.updated_at',
            ['owner' => $userId, 'now' => $now] + $details->toRow()
        );
    }

    public function saveForCompany(int $companyId, BillingDetails $details, string $now): void
    {
        $row = $details->toRow();
        unset($row['organisation_name']);
        $this->db->executeStatement(
            'INSERT INTO company_billing_profiles (company_id, ' . self::COMPANY_COLUMNS . ', created_at, updated_at)
             VALUES (:owner, :billing_name, :tax_registration_number, :address_line_1, :address_line_2, :locality, :city, :region, :postal_code, :country_code, :now, :now)
             ON CONFLICT (company_id) DO UPDATE SET ' . self::assignments(self::COMPANY_COLUMNS) . ', updated_at = EXCLUDED.updated_at',
            ['owner' => $companyId, 'now' => $now] + $row
        );
    }

    private static function assignments(string $columns): string
    {
        return implode(', ', array_map(static fn(string $column): string => $column . ' = EXCLUDED.' . $column, array_map('trim', explode(',', $columns))));
    }
}

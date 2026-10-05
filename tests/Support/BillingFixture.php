<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Support;

use CattoLearning\Commerce\Application\BillingProfileService;
use CattoLearning\Commerce\Domain\BillingDetails;
use CattoLearning\Commerce\Infrastructure\BillingProfileRepository;
use CattoLearning\Infrastructure\Persistence\AdministrationRepository;
use CattoLearning\Infrastructure\Persistence\AuditRepository;
use CattoLearning\Infrastructure\Persistence\CompanyRepository;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Infrastructure\Persistence\TransactionManager;
use Symfony\Component\Clock\ClockInterface;

/**
 * Realistic structured billing details for commerce tests, and a BillingProfileService bound to a
 * test's own database connection and clock.
 */
final class BillingFixture
{
    /** @return array<string,string> */
    public static function personFields(string $name = 'Thandiwe Nkosi', string $line1 = '14 Jacaranda Avenue'): array
    {
        return [
            'billing_name' => $name, 'organisation_name' => 'Nkosi Consulting', 'tax_registration_number' => '4012345678',
            'address_line_1' => $line1, 'address_line_2' => 'Unit 3', 'locality' => 'Hatfield', 'city' => 'Pretoria',
            'region' => 'Gauteng', 'postal_code' => '0083', 'country_code' => 'ZA',
        ];
    }

    /** @return array<string,string> */
    public static function companyFields(string $name = 'Acme Training (Pty) Ltd', string $line1 = '1 Dock Road'): array
    {
        return [
            'billing_name' => $name, 'tax_registration_number' => '4987654321', 'address_line_1' => $line1, 'address_line_2' => '',
            'locality' => 'V&A Waterfront', 'city' => 'Cape Town', 'region' => 'Western Cape', 'postal_code' => '8001', 'country_code' => 'ZA',
        ];
    }

    public static function person(string $name = 'Thandiwe Nkosi', string $line1 = '14 Jacaranda Avenue'): BillingDetails
    {
        return BillingDetails::forPerson(self::personFields($name, $line1));
    }

    public static function company(string $name = 'Acme Training (Pty) Ltd', string $line1 = '1 Dock Road'): BillingDetails
    {
        return BillingDetails::forCompany(self::companyFields($name, $line1));
    }

    public static function service(Database $db, ClockInterface $clock): BillingProfileService
    {
        $container = IntegrationContainer::get();
        return new BillingProfileService(new BillingProfileRepository($db), $container->get(CompanyRepository::class),
            $container->get(AdministrationRepository::class), $container->get(AuditRepository::class), new TransactionManager($db), $clock);
    }
}

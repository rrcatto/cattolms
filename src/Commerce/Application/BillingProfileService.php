<?php

declare(strict_types=1);

namespace CattoLearning\Commerce\Application;

use CattoLearning\Auth\CurrentUser;
use CattoLearning\Commerce\Domain\BillingDetails;
use CattoLearning\Commerce\Infrastructure\BillingProfileRepository;
use CattoLearning\Infrastructure\Persistence\AdministrationRepository;
use CattoLearning\Infrastructure\Persistence\AuditRepository;
use CattoLearning\Infrastructure\Persistence\CompanyRepository;
use CattoLearning\Infrastructure\Persistence\TransactionManager;
use CattoLearning\Support\Env;
use RuntimeException;
use Symfony\Component\Clock\ClockInterface;

/**
 * The reusable billing profiles of people and companies: reading them for forms and checkout,
 * and saving them. A profile is current data that its owner may change at any time; an order
 * copies it into an immutable snapshot when it is placed, and documents never read it again.
 *
 * A person changes only their own profile. A company's profile is the company's, not its
 * administrator's: changing it needs COMPANY.BILLING.MANAGE and membership of that company, or
 * platform authority, and every change is recorded in the company audit trail.
 */
final class BillingProfileService
{
    public function __construct(
        private readonly BillingProfileRepository $profiles,
        private readonly CompanyRepository $companies,
        private readonly AdministrationRepository $administration,
        private readonly AuditRepository $audit,
        private readonly TransactionManager $transactions,
        private readonly ClockInterface $clock,
    ) {
    }

    public function forUser(int $userId): ?BillingDetails
    {
        return $this->profiles->forUser($userId);
    }

    public function forCompany(int $companyId): ?BillingDetails
    {
        return $this->profiles->forCompany($companyId);
    }

    /**
     * What a person's billing form shows: their saved profile, or a starting point made from their
     * name and the installation's country.
     *
     * @param array<string,mixed> $personalProfile the person's profile row
     * @return array{values:array<string,string>,saved:bool}
     */
    public function userForm(int $userId, array $personalProfile): array
    {
        $saved = $this->profiles->forUser($userId);
        if ($saved !== null) return ['values' => self::strings($saved->toRow()), 'saved' => true];
        $name = trim(trim((string) ($personalProfile['first_name'] ?? '')) . ' ' . trim((string) ($personalProfile['last_name'] ?? '')));
        return ['values' => self::blank(['billing_name' => $name, 'country_code' => self::defaultCountry()]), 'saved' => false];
    }

    /**
     * What a company's billing form shows: its saved profile, or its current name and the
     * installation's country as a starting point.
     *
     * @return array{values:array<string,string>,saved:bool}
     */
    public function companyForm(int $companyId, string $companyName): array
    {
        $saved = $this->profiles->forCompany($companyId);
        if ($saved !== null) return ['values' => self::strings($saved->toRow()), 'saved' => true];
        return ['values' => self::blank(['billing_name' => $companyName, 'country_code' => self::defaultCountry()]), 'saved' => false];
    }

    /**
     * Validates and saves the signed-in person's own billing profile. There is no target user: the
     * profile saved is always the actor's.
     *
     * @param array<string,mixed> $input
     */
    public function saveOwn(CurrentUser $actor, array $input): BillingDetails
    {
        $details = BillingDetails::forPerson($input);
        $this->transactions->run(function () use ($actor, $details): void {
            $current = $this->profiles->forUser($actor->id);
            if ($current !== null && $current->equals($details)) return;
            $this->profiles->saveForUser($actor->id, $details, $this->clock->now()->format(DATE_ATOM));
            $this->audit->record($actor->id, 'user.billing_profile_updated', ['user_id' => $actor->id]);
        });
        return $details;
    }

    /**
     * Saves a company's billing profile for an authorised member of that company, recording the
     * change in the company audit trail. Saving identical details changes nothing.
     *
     * @return bool whether anything changed
     */
    public function saveForCompany(CurrentUser $actor, int $companyId, BillingDetails $details): bool
    {
        $this->requireCompanyAuthority($actor, $companyId);
        return $this->transactions->run(function () use ($actor, $companyId, $details): bool {
            $current = $this->profiles->forCompany($companyId);
            if ($current !== null && $current->equals($details)) return false;
            $this->profiles->saveForCompany($companyId, $details, $this->clock->now()->format(DATE_ATOM));
            $this->audit->record($actor->id, 'company.billing_updated', ['company_id' => $companyId]);
            return true;
        });
    }

    /** The company's billing may be changed by this actor: the capability, and the company's own scope. */
    public function requireCompanyAuthority(CurrentUser $actor, int $companyId): void
    {
        OrderService::requireCapability($actor, 'COMPANY.BILLING.MANAGE');
        $company = $companyId > 0 ? $this->companies->findById($companyId) : null;
        if ($company === null || (string) $company['status'] !== 'active') throw new RuntimeException('Select an active company first.');
        if (!$actor->hasPermission('PLATFORM.DASHBOARD.VIEW') && !$this->administration->isActiveCompanyMember($companyId, $actor->id)) {
            throw new RuntimeException('You may change billing details only for the company you administer.');
        }
    }

    /** The installation's country (APP_COUNTRY) as the default for a new profile, or blank when unset or malformed. */
    public static function defaultCountry(): string
    {
        return BillingDetails::normaliseCountryCode(Env::string('APP_COUNTRY', '')) ?? '';
    }

    /**
     * @param array<string,?string> $row
     * @return array<string,string>
     */
    private static function strings(array $row): array
    {
        return array_map(static fn(?string $value): string => (string) $value, $row);
    }

    /**
     * @param array<string,string> $values
     * @return array<string,string>
     */
    private static function blank(array $values): array
    {
        return $values + array_fill_keys(array_keys(BillingDetails::LIMITS), '');
    }
}

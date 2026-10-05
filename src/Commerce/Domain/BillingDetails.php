<?php

declare(strict_types=1);

namespace CattoLearning\Commerce\Domain;

use InvalidArgumentException;

/**
 * One set of billing details: who an invoice is made out to and where. The same value serves both
 * the mutable reusable profile (user_billing_profiles, company_billing_profiles) and the immutable
 * copy frozen into an order and its documents, so a profile and a snapshot can never disagree about
 * which fields exist or how they are validated and formatted.
 *
 * The tax registration number is billing identity only: it is printed when present and never
 * enables or calculates tax. A company's billing name is itself the legal billing identity, so a
 * company has no separate organisation name.
 */
final readonly class BillingDetails
{
    /** Field => maximum length in characters. The order is the order forms and rows use. */
    public const LIMITS = [
        'billing_name' => 200, 'organisation_name' => 200, 'tax_registration_number' => 40,
        'address_line_1' => 200, 'address_line_2' => 200, 'locality' => 120, 'city' => 120,
        'region' => 120, 'postal_code' => 20, 'country_code' => 2,
    ];

    private const REQUIRED = ['billing_name' => 'billing name', 'address_line_1' => 'first address line', 'city' => 'city', 'country_code' => 'country'];

    private const LABELS = [
        'billing_name' => 'Billing name', 'organisation_name' => 'Organisation or trading name', 'tax_registration_number' => 'Tax or VAT registration number',
        'address_line_1' => 'Address line 1', 'address_line_2' => 'Address line 2', 'locality' => 'Suburb or locality', 'city' => 'City or town',
        'region' => 'Province, state or region', 'postal_code' => 'Postal code', 'country_code' => 'Country code',
    ];

    private function __construct(
        public string $name,
        public ?string $organisationName,
        public ?string $taxRegistrationNumber,
        public string $line1,
        public ?string $line2,
        public ?string $locality,
        public string $city,
        public ?string $region,
        public ?string $postalCode,
        public string $countryCode,
    ) {
    }

    /**
     * Validated details from submitted form fields, for a person (who may bill an organisation).
     *
     * @param array<string,mixed> $input
     * @throws InvalidArgumentException naming the first field that is missing, too long or malformed
     */
    public static function forPerson(array $input): self
    {
        return self::fromFields($input, true);
    }

    /**
     * Validated details from submitted form fields, for a company: its billing name is the legal
     * billing identity, so any organisation name submitted is ignored.
     *
     * @param array<string,mixed> $input
     */
    public static function forCompany(array $input): self
    {
        return self::fromFields($input, false);
    }

    /**
     * From a stored profile row (columns named as the form fields).
     *
     * @param array<string,mixed> $row
     */
    public static function fromRow(array $row, bool $person): self
    {
        return self::fromFields($row, $person);
    }

    /**
     * From an immutable order or document snapshot's `billing` element. Snapshots are only ever
     * written by toSnapshot(), so a missing key is a corrupt document, not something to guess at.
     *
     * @param array<string,mixed> $billing
     */
    public static function fromSnapshot(array $billing): self
    {
        $address = $billing['address'] ?? null;
        if (!is_array($address) || !isset($billing['name'], $address['line_1'], $address['city'], $address['country_code'])) {
            throw new InvalidArgumentException('This document has no billing snapshot.');
        }
        $text = static fn(mixed $value): ?string => $value === null || $value === '' ? null : (string) $value;
        return new self((string) $billing['name'], $text($billing['organisation_name'] ?? null), $text($billing['tax_registration_number'] ?? null),
            (string) $address['line_1'], $text($address['line_2'] ?? null), $text($address['locality'] ?? null), (string) $address['city'],
            $text($address['region'] ?? null), $text($address['postal_code'] ?? null), (string) $address['country_code']);
    }

    /**
     * The structured, immutable form stored in an order and every document made from it. Each
     * field stays separate so a future document template can place them individually.
     *
     * @return array{name:string,organisation_name:?string,tax_registration_number:?string,address:array{line_1:string,line_2:?string,locality:?string,city:string,region:?string,postal_code:?string,country_code:string}}
     */
    public function toSnapshot(): array
    {
        return [
            'name' => $this->name,
            'organisation_name' => $this->organisationName,
            'tax_registration_number' => $this->taxRegistrationNumber,
            'address' => [
                'line_1' => $this->line1, 'line_2' => $this->line2, 'locality' => $this->locality, 'city' => $this->city,
                'region' => $this->region, 'postal_code' => $this->postalCode, 'country_code' => $this->countryCode,
            ],
        ];
    }

    /**
     * Column => value for a profile row, and the values a form is prefilled with.
     *
     * @return array<string,?string>
     */
    public function toRow(): array
    {
        return [
            'billing_name' => $this->name, 'organisation_name' => $this->organisationName, 'tax_registration_number' => $this->taxRegistrationNumber,
            'address_line_1' => $this->line1, 'address_line_2' => $this->line2, 'locality' => $this->locality, 'city' => $this->city,
            'region' => $this->region, 'postal_code' => $this->postalCode, 'country_code' => $this->countryCode,
        ];
    }

    /** Whether two sets of details would print the same invoice. */
    public function equals(self $other): bool
    {
        return $this->toRow() === $other->toRow();
    }

    /**
     * The postal address as display lines, empty optional parts left out: street lines, locality,
     * city with postal code, region, then the country's name.
     *
     * @return list<string>
     */
    public function addressLines(?string $displayLocale = null): array
    {
        $cityLine = trim($this->city . ($this->postalCode !== null ? ' ' . $this->postalCode : ''));
        return array_values(array_filter([$this->line1, $this->line2, $this->locality, $cityLine, $this->region, self::countryName($this->countryCode, $displayLocale)],
            static fn(?string $line): bool => $line !== null && $line !== ''));
    }

    /**
     * Everything a document prints under "Billed to", in order: the name, the organisation, the
     * address and the tax registration number.
     *
     * @return list<string>
     */
    public function documentLines(?string $displayLocale = null): array
    {
        return array_merge(
            [$this->name],
            $this->organisationName !== null ? [$this->organisationName] : [],
            $this->addressLines($displayLocale),
            $this->taxRegistrationNumber !== null ? ['Tax/VAT number: ' . $this->taxRegistrationNumber] : [],
        );
    }

    /** The English (or given locale's) name of an ISO 3166-1 alpha-2 code, or the code itself. */
    public static function countryName(string $code, ?string $displayLocale = null): string
    {
        $name = class_exists(\Locale::class) ? (string) \Locale::getDisplayRegion('-' . $code, $displayLocale ?? 'en') : '';
        return $name === '' || $name === $code ? $code : $name;
    }

    /**
     * The fields a billing form shows, in order, with what the shared form partial needs: label,
     * length, whether required, and the browser's billing autocomplete token. A company form has no
     * organisation name: the company's billing name is its billing identity.
     *
     * @return list<array{name:string,label:string,limit:int,required:bool,autocomplete:string,full_width:bool,help:string}>
     */
    public static function formFields(bool $person): array
    {
        $autocomplete = [
            'billing_name' => 'billing name', 'organisation_name' => 'billing organization', 'tax_registration_number' => 'off',
            'address_line_1' => 'billing address-line1', 'address_line_2' => 'billing address-line2', 'locality' => 'billing address-level3',
            'city' => 'billing address-level2', 'region' => 'billing address-level1', 'postal_code' => 'billing postal-code', 'country_code' => 'billing country',
        ];
        $help = [
            'organisation_name' => 'Optional. A business or trading name to put on your invoices.',
            'tax_registration_number' => 'Optional. Printed on invoices for your records; no tax is charged.',
            'country_code' => 'The two-letter ISO 3166-1 code.',
        ];
        $fields = [];
        foreach (self::LIMITS as $field => $limit) {
            if (!$person && $field === 'organisation_name') continue;
            $fields[] = ['name' => $field, 'label' => self::label($field), 'limit' => $limit, 'required' => isset(self::REQUIRED[$field]),
                'autocomplete' => $autocomplete[$field], 'full_width' => in_array($field, ['address_line_1', 'address_line_2'], true), 'help' => $help[$field] ?? ''];
        }
        return $fields;
    }

    /** The label a form shows for a field. */
    public static function label(string $field): string
    {
        return self::LABELS[$field] ?? $field;
    }

    /** An upper-case ISO 3166-1 alpha-2 shape, or null. */
    public static function normaliseCountryCode(string $code): ?string
    {
        $code = strtoupper(trim($code));
        return preg_match('/^[A-Z]{2}$/D', $code) === 1 ? $code : null;
    }

    /** @param array<string,mixed> $input */
    private static function fromFields(array $input, bool $person): self
    {
        $value = [];
        foreach (self::LIMITS as $field => $limit) {
            if (!$person && $field === 'organisation_name') { $value[$field] = null; continue; }
            $raw = $input[$field] ?? null;
            if ($raw !== null && !is_scalar($raw)) throw new InvalidArgumentException(self::label($field) . ' must be text.');
            $text = (string) ($raw ?? '');
            if ($text !== '' && preg_match('//u', $text) !== 1) throw new InvalidArgumentException(self::label($field) . ' is not valid text.');
            // Surrounding whitespace, including non-breaking and other Unicode spaces, is dropped;
            // what lies between is kept exactly, accents and punctuation included.
            $text = (string) preg_replace('/^\s+|\s+$/u', '', $text);
            if (preg_match('/[\x00-\x1F\x7F]/u', $text) === 1) throw new InvalidArgumentException(self::label($field) . ' must be a single line of text.');
            if (mb_strlen($text) > $limit) throw new InvalidArgumentException(self::label($field) . ' may not exceed ' . $limit . ' characters.');
            if ($text === '' && isset(self::REQUIRED[$field])) throw new InvalidArgumentException('Enter the ' . self::REQUIRED[$field] . '.');
            $value[$field] = $text === '' ? null : $text;
        }
        $country = self::normaliseCountryCode((string) $value['country_code']);
        if ($country === null) throw new InvalidArgumentException('Enter the country as its two-letter ISO 3166-1 code, such as GB or US.');
        return new self((string) $value['billing_name'], $value['organisation_name'], $value['tax_registration_number'], (string) $value['address_line_1'],
            $value['address_line_2'], $value['locality'], (string) $value['city'], $value['region'], $value['postal_code'], $country);
    }
}

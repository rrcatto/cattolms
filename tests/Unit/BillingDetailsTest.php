<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Commerce\Domain\BillingDetails;
use CattoLearning\Tests\Support\BillingFixture;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Billing details without a database: what is required, the length of every field, trimming that
 * keeps accents and punctuation, the country code's shape, the immutable snapshot's exact
 * structure, and the lines a document prints.
 */
final class BillingDetailsTest extends TestCase
{
    public function testRequiredFieldsAndOptionalBlanks(): void
    {
        $details = BillingDetails::forPerson(['billing_name' => 'A', 'address_line_1' => '1 Road', 'city' => 'Town', 'country_code' => 'ZA', 'organisation_name' => '   ', 'region' => '']);
        self::assertNull($details->organisationName, 'Blank optional fields are stored as null.');
        self::assertNull($details->region);
        foreach (['billing_name' => 'billing name', 'address_line_1' => 'first address line', 'city' => 'city', 'country_code' => 'Enter the country'] as $field => $message) {
            $input = BillingFixture::personFields();
            $input[$field] = '  ';
            $this->refused($input, $message);
        }
        $input = BillingFixture::personFields();
        unset($input['city']);
        $this->refused($input, 'city');
    }

    public function testEveryFieldHasAnExplicitLengthBound(): void
    {
        foreach (BillingDetails::LIMITS as $field => $limit) {
            if ($field === 'country_code') continue;
            // A South African address's province is one of the nine; any other country's is typed.
            $input = ($field === 'region' ? ['country_code' => 'GB'] : []) + BillingFixture::personFields();
            $input[$field] = str_repeat('é', $limit);
            self::assertSame(str_repeat('é', $limit), BillingDetails::forPerson($input)->toRow()[$field], $field . ' accepts ' . $limit . ' characters, counted as characters.');
            $input[$field] = str_repeat('é', $limit + 1);
            $this->refused($input, 'may not exceed ' . $limit);
        }
    }

    public function testTrimmingKeepsAccentsPunctuationAndNonLatinText(): void
    {
        $details = BillingDetails::forPerson([
            'billing_name' => "\u{00A0} Zoë O’Brien-Ndlovu \t", 'organisation_name' => 'Ünïcode & Söhne, “GmbH”', 'address_line_1' => '12 Rue de l’Église, Apt. #4/B',
            'city' => 'Δελφοί', 'region' => '東京都', 'postal_code' => ' 0083 ', 'country_code' => ' gr ',
        ]);
        self::assertSame('Zoë O’Brien-Ndlovu', $details->name);
        self::assertSame('Ünïcode & Söhne, “GmbH”', $details->organisationName);
        self::assertSame('12 Rue de l’Église, Apt. #4/B', $details->line1);
        self::assertSame('Δελφοί', $details->city);
        self::assertSame('東京都', $details->region);
        self::assertSame('0083', $details->postalCode);
        self::assertSame('GR', $details->countryCode, 'The country code is normalised to upper case.');
    }

    public function testMalformedInputIsRefused(): void
    {
        // A country is one from the list, chosen by name (owner, 2026-10-27); a made-up code is refused too.
        foreach (['Z' => 'Choose the country from the list', 'ZAF' => 'may not exceed 2', '1A' => 'Choose the country from the list', 'Z-' => 'Choose the country from the list',
            'ÄB' => 'Choose the country from the list', 'QZ' => 'Choose the country from the list', '' => 'Enter the country'] as $country => $message) {
            $this->refused(['country_code' => (string) $country] + BillingFixture::personFields(), $message);
        }
        $this->refused(['address_line_1' => "Line one\nline two"] + BillingFixture::personFields(), 'single line');
        $this->refused(['city' => "Tab\tCity"] + BillingFixture::personFields(), 'single line');
        $this->refused(['billing_name' => ['an', 'array']] + BillingFixture::personFields(), 'must be text');
        $this->refused(['billing_name' => "\xC3\x28"] + BillingFixture::personFields(), 'not valid text');
    }

    public function testACompanyHasNoOrganisationNameAndItsFormHasNoSuchField(): void
    {
        $company = BillingDetails::forCompany(BillingFixture::companyFields() + ['organisation_name' => 'Ignored']);
        self::assertNull($company->organisationName);
        self::assertNotContains('organisation_name', array_column(BillingDetails::formFields(false), 'name'));
        self::assertContains('organisation_name', array_column(BillingDetails::formFields(true), 'name'));
        foreach (BillingDetails::formFields(true) as $field) {
            self::assertSame(BillingDetails::LIMITS[$field['name']], $field['limit']);
            self::assertStringStartsWith($field['name'] === 'tax_registration_number' ? 'off' : 'billing ', $field['autocomplete']);
        }
    }

    public function testTheSnapshotIsStructuredAndRebuildsExactly(): void
    {
        $details = BillingFixture::person();
        self::assertSame([
            'name' => 'Thandiwe Nkosi', 'organisation_name' => 'Nkosi Consulting', 'tax_registration_number' => '4012345678',
            'address' => ['line_1' => '14 Jacaranda Avenue', 'line_2' => 'Unit 3', 'locality' => 'Hatfield', 'city' => 'Pretoria', 'region' => 'Gauteng', 'postal_code' => '0083', 'country_code' => 'ZA'],
        ], $details->toSnapshot());
        $copy = BillingDetails::fromSnapshot(json_decode(json_encode($details->toSnapshot(), JSON_THROW_ON_ERROR), true));
        self::assertTrue($details->equals($copy));
        self::assertFalse($details->equals(BillingFixture::person('Someone Else')));
        $this->expectException(InvalidArgumentException::class);
        BillingDetails::fromSnapshot(['billing_name' => 'Old flat shape', 'billing_address' => 'One string']);
    }

    public function testDocumentLinesOmitEmptyPartsAndKeepEveryStoredValue(): void
    {
        self::assertSame(
            ['Thandiwe Nkosi', 'Nkosi Consulting', '14 Jacaranda Avenue', 'Unit 3', 'Hatfield', 'Pretoria 0083', 'Gauteng', 'South Africa', 'Tax/VAT number: 4012345678'],
            BillingFixture::person()->documentLines('en')
        );
        $minimal = BillingDetails::forCompany(['billing_name' => 'Acme', 'address_line_1' => '1 Road', 'city' => 'London', 'country_code' => 'GB']);
        self::assertSame(['Acme', '1 Road', 'London', 'United Kingdom'], $minimal->documentLines('en'));
        self::assertSame('QZ', BillingDetails::countryName('QZ'), 'An unknown code prints as itself.');
    }

    public function testASouthAfricanAddressHasOneOfTheNineProvincesAndAnyOtherCountryATypedOne(): void
    {
        self::assertSame('KwaZulu-Natal', BillingDetails::forPerson(['country_code' => 'ZA', 'region' => ' kwazulu-natal '] + BillingFixture::personFields())->region, 'Written as the list writes it.');
        $this->refused(['country_code' => 'ZA', 'region' => 'Natal'] + BillingFixture::personFields(), 'Choose the province from the list');
        self::assertNull(BillingDetails::forPerson(['country_code' => 'ZA', 'region' => ''] + BillingFixture::personFields())->region, 'The province may be left out.');
        // The form sends both fields: the drop-down for South Africa (region) and the text box for any other country (region_other).
        self::assertSame('Gauteng', BillingDetails::forPerson(['country_code' => 'ZA', 'region' => 'Gauteng', 'region_other' => 'Ontario'] + BillingFixture::personFields())->region);
        self::assertSame('Ontario', BillingDetails::forPerson(['country_code' => 'CA', 'region' => 'Gauteng', 'region_other' => 'Ontario'] + BillingFixture::personFields())->region);
        self::assertSame('Bavaria', BillingDetails::forPerson(['country_code' => 'DE', 'region' => 'Bavaria'] + BillingFixture::personFields())->region, 'A caller without the text box still gives the province.');
    }

    public function testTheFormShowsCountriesByNameSouthAfricaFirstAndTheNineProvinces(): void
    {
        $fields = array_column(BillingDetails::formFields(true), null, 'name');
        self::assertSame('Country', $fields['country_code']['label']);
        self::assertSame(['code' => 'ZA', 'name' => 'South Africa'], $fields['country_code']['options'][0]);
        self::assertCount(249, $fields['country_code']['options']);
        self::assertSame(['Eastern Cape', 'Free State', 'Gauteng', 'KwaZulu-Natal', 'Limpopo', 'Mpumalanga', 'North West', 'Northern Cape', 'Western Cape'], $fields['region']['options']);
        self::assertLessThan(array_search('region', array_keys($fields), true), array_search('country_code', array_keys($fields), true), 'The country comes before the province, which depends on it.');
    }

    /** @param array<string,mixed> $input */
    private function refused(array $input, string $message): void
    {
        try {
            BillingDetails::forPerson($input);
            self::fail('Expected refusal: ' . $message);
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString($message, $error->getMessage());
        }
    }
}

<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Help\Faq;
use CattoLearning\Search\CatalogueStructuredData;
use CattoPHP\Search\StructuredData;
use PHPUnit\Framework\TestCase;

/**
 * Search engines and AI (owner, 2026-11-06, "2A 3A"): the structured data of a course, a bundle and the FAQ. The rest (the
 * other nodes and their rules, the AI crawler list, the verification codes and the share pictures) is CattoPHP's search
 * engines module, tested there (CattoPHP Phase 4, step 5).
 */
final class SearchEnginesTest extends TestCase
{
    /**
     * @param array<string,mixed> $changes
     * @return array{id:string,url:string,name:string,description:string,code:string,language:string,provider:array{name:string,url:string,id:string},image:string,level:string,workload_minutes:int,offers:list<array{name:string,price:string,currency:string,url:string}>,rating:?array{value:string,count:int}}
     */
    private static function course(array $changes = []): array
    {
        return $changes + [
            'id' => 'https://learning.example.test/courses/garden-design#course', 'url' => 'https://learning.example.test/courses/garden-design',
            'name' => 'Garden Design', 'description' => 'Plan a garden from the soil up.', 'code' => 'GDN-101', 'language' => 'en-ZA',
            'provider' => ['name' => 'Example Learning', 'url' => 'https://learning.example.test/', 'id' => 'https://learning.example.test/#organization'],
            'image' => '', 'level' => 'Beginner', 'workload_minutes' => 7500,
            'offers' => [['name' => '1 year of access', 'price' => '2500.00', 'currency' => 'ZAR', 'url' => 'https://learning.example.test/courses/garden-design']],
            'rating' => null,
        ];
    }

    public function testACourseCarriesWhatTheBriefAsksAndNoRatingWithoutAReview(): void
    {
        $node = CatalogueStructuredData::course(self::course());
        self::assertSame('Course', $node['@type']);
        self::assertSame('https://schema.org', $node['@context']);
        foreach (['name' => 'Garden Design', 'description' => 'Plan a garden from the soil up.', 'courseCode' => 'GDN-101', 'inLanguage' => 'en-ZA', 'educationalLevel' => 'Beginner'] as $key => $value) {
            self::assertSame($value, $node[$key], $key);
        }
        self::assertSame(['@type' => 'Organization', '@id' => 'https://learning.example.test/#organization', 'name' => 'Example Learning', 'url' => 'https://learning.example.test/'], $node['provider']);
        self::assertSame([['@type' => 'CourseInstance', 'courseMode' => 'Online', 'courseWorkload' => 'PT125H']], $node['hasCourseInstance']);
        self::assertSame(['@type' => 'Offer', 'name' => '1 year of access', 'category' => 'Paid', 'price' => '2500.00', 'priceCurrency' => 'ZAR', 'availability' => 'https://schema.org/InStock', 'url' => 'https://learning.example.test/courses/garden-design'], $node['offers'][0]);
        self::assertArrayNotHasKey('aggregateRating', $node, 'No rating without an approved review.');
        self::assertArrayNotHasKey('image', $node, 'No picture until its PNG is drawn.');

        $rated = CatalogueStructuredData::course(self::course(['rating' => ['value' => '4.5', 'count' => 2], 'offers' => [], 'level' => '', 'workload_minutes' => 0]));
        self::assertSame(['@type' => 'AggregateRating', 'ratingValue' => '4.5', 'ratingCount' => 2, 'bestRating' => 5, 'worstRating' => 1], $rated['aggregateRating']);
        self::assertArrayNotHasKey('offers', $rated, 'Not on sale, no Offer.');
        self::assertArrayNotHasKey('educationalLevel', $rated, 'A level the page does not show is not marked up.');
        self::assertSame([['@type' => 'CourseInstance', 'courseMode' => 'Online']], $rated['hasCourseInstance']);
        self::assertArrayNotHasKey('aggregateRating', CatalogueStructuredData::course(self::course(['rating' => ['value' => '0.0', 'count' => 0]])));
    }

    public function testEachNodeIsEncodedSoItCannotEndItsScript(): void
    {
        $json = StructuredData::encode(CatalogueStructuredData::course(self::course(['description' => 'Ends here </script><script>alert(1)</script> & more'])));
        self::assertStringNotContainsString('</script>', $json);
        self::assertStringNotContainsString('<', $json);
        self::assertSame('Ends here </script><script>alert(1)</script> & more', json_decode($json, true, 512, JSON_THROW_ON_ERROR)['description']);
        self::assertStringContainsString('https://learning.example.test/courses/garden-design', $json, 'Addresses are not escaped.');
    }

    public function testABundleIsAProductWithItsOfferOnlyWhileOnSale(): void
    {
        $bundle = ['id' => 'https://learning.example.test/bundles/garden#bundle', 'url' => 'https://learning.example.test/bundles/garden', 'name' => 'The garden set', 'description' => 'Three garden courses.', 'image' => 'https://learning.example.test/share/bundle/garden.png?v=abc',
            'brand' => ['name' => 'Example Learning', 'url' => 'https://learning.example.test/', 'id' => 'https://learning.example.test/#organization'],
            'offer' => ['price' => '4000.00', 'currency' => 'ZAR', 'url' => 'https://learning.example.test/bundles/garden', 'valid_from' => '', 'valid_until' => '2026-12-31']];
        $node = CatalogueStructuredData::bundle($bundle);
        self::assertSame('Product', $node['@type']);
        self::assertSame(['@type' => 'Offer', 'price' => '4000.00', 'priceCurrency' => 'ZAR', 'availability' => 'https://schema.org/InStock', 'url' => 'https://learning.example.test/bundles/garden', 'priceValidUntil' => '2026-12-31'], $node['offers']);
        self::assertArrayNotHasKey('offers', CatalogueStructuredData::bundle(['offer' => null] + $bundle));
    }

    public function testTheFaqIsOneListForItsPageAndItsMarkup(): void
    {
        $faq = new Faq();
        $answered = $faq->answered('https://learning.example.test');
        self::assertCount(count($faq->questions()), $answered);
        $grading = array_values(array_filter($answered, static fn(array $q): bool => $q['question'] === 'How is my result worked out?'))[0];
        self::assertStringContainsString('<li>First Class (A): 75% and above</li>', $grading['answer'], 'The scale the page draws is in the answer.');
        $refunds = array_values(array_filter($answered, static fn(array $q): bool => $q['question'] === 'Can I get a refund?'))[0];
        self::assertStringContainsString('href="https://learning.example.test/refunds"', $refunds['answer'], 'A link is absolute away from the page.');
        $node = StructuredData::faq('https://learning.example.test/faq', $answered);
        self::assertSame('FAQPage', $node['@type']);
        self::assertSame('Question', $node['mainEntity'][0]['@type']);
        self::assertSame('Answer', $node['mainEntity'][0]['acceptedAnswer']['@type']);
    }
}

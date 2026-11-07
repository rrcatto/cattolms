<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Help\Faq;
use CattoLearning\Search\AiCrawlers;
use CattoLearning\Search\SearchSettings;
use CattoLearning\Search\SharePictures;
use CattoLearning\Search\StructuredData;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Search engines and AI (owner, 2026-11-06, "2A 3A"): the structured data each public page carries, the AI crawler list
 * robots.txt follows, the verification codes and the share pictures' canvas.
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
        $node = StructuredData::course(self::course());
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

        $rated = StructuredData::course(self::course(['rating' => ['value' => '4.5', 'count' => 2], 'offers' => [], 'level' => '', 'workload_minutes' => 0]));
        self::assertSame(['@type' => 'AggregateRating', 'ratingValue' => '4.5', 'ratingCount' => 2, 'bestRating' => 5, 'worstRating' => 1], $rated['aggregateRating']);
        self::assertArrayNotHasKey('offers', $rated, 'Not on sale, no Offer.');
        self::assertArrayNotHasKey('educationalLevel', $rated, 'A level the page does not show is not marked up.');
        self::assertSame([['@type' => 'CourseInstance', 'courseMode' => 'Online']], $rated['hasCourseInstance']);
        self::assertArrayNotHasKey('aggregateRating', StructuredData::course(self::course(['rating' => ['value' => '0.0', 'count' => 0]])));
    }

    public function testTheStudyTimeIsWordedAsThePageWordsIt(): void
    {
        foreach ([0 => null, 3 => 'PT5M', 23 => 'PT25M', 54 => 'PT55M', 60 => 'PT1H', 95 => 'PT1H30M', 150 => 'PT2H30M', 599 => 'PT10H', 7500 => 'PT125H', 9000 => 'PT150H'] as $minutes => $iso) {
            self::assertSame($iso, StructuredData::duration($minutes), $minutes . ' minutes');
        }
    }

    public function testEachNodeIsEncodedSoItCannotEndItsScript(): void
    {
        $json = StructuredData::encode(StructuredData::course(self::course(['description' => 'Ends here </script><script>alert(1)</script> & more'])));
        self::assertStringNotContainsString('</script>', $json);
        self::assertStringNotContainsString('<', $json);
        self::assertSame('Ends here </script><script>alert(1)</script> & more', json_decode($json, true, 512, JSON_THROW_ON_ERROR)['description']);
        self::assertStringContainsString('https://learning.example.test/courses/garden-design', $json, 'Addresses are not escaped.');
    }

    public function testATrailOfTwoStepsOrMoreBecomesABreadcrumbList(): void
    {
        self::assertNull(StructuredData::breadcrumbs([['label' => 'Home', 'href' => '', 'current' => true]], 'https://learning.example.test', '/'), 'The home page has no trail.');
        $trail = StructuredData::breadcrumbs([['label' => 'Home', 'href' => '/', 'current' => false], ['label' => 'Catalogue', 'href' => '/courses', 'current' => false], ['label' => 'Garden Design', 'href' => '', 'current' => true]], 'https://learning.example.test', '/courses/garden-design');
        self::assertNotNull($trail);
        self::assertSame('BreadcrumbList', $trail['@type']);
        self::assertSame([
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://learning.example.test/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Catalogue', 'item' => 'https://learning.example.test/courses'],
            ['@type' => 'ListItem', 'position' => 3, 'name' => 'Garden Design', 'item' => 'https://learning.example.test/courses/garden-design'],
        ], $trail['itemListElement']);
        self::assertNull(StructuredData::breadcrumbs([['label' => 'My Courses', 'href' => '', 'current' => false], ['label' => 'Lesson', 'href' => '', 'current' => true]], 'https://learning.example.test', '/x'), 'A step without an address before the last is no trail.');
    }

    public function testACourseListNeedsThreeCourses(): void
    {
        self::assertNull(StructuredData::courseList('https://learning.example.test/courses', 'Course catalogue', ['https://learning.example.test/courses/a', 'https://learning.example.test/courses/b']));
        $list = StructuredData::courseList('https://learning.example.test/courses', 'Course catalogue', ['https://learning.example.test/courses/a', 'https://learning.example.test/courses/b', 'https://learning.example.test/courses/c']);
        self::assertNotNull($list);
        self::assertSame('ItemList', $list['@type']);
        self::assertSame(['@type' => 'ListItem', 'position' => 3, 'url' => 'https://learning.example.test/courses/c'], $list['itemListElement'][2]);
    }

    public function testABundleIsAProductWithItsOfferOnlyWhileOnSale(): void
    {
        $bundle = ['id' => 'https://learning.example.test/bundles/garden#bundle', 'url' => 'https://learning.example.test/bundles/garden', 'name' => 'The garden set', 'description' => 'Three garden courses.', 'image' => 'https://learning.example.test/share/bundle/garden.png?v=abc',
            'brand' => ['name' => 'Example Learning', 'url' => 'https://learning.example.test/', 'id' => 'https://learning.example.test/#organization'],
            'offer' => ['price' => '4000.00', 'currency' => 'ZAR', 'url' => 'https://learning.example.test/bundles/garden', 'valid_from' => '', 'valid_until' => '2026-12-31']];
        $node = StructuredData::bundle($bundle);
        self::assertSame('Product', $node['@type']);
        self::assertSame(['@type' => 'Offer', 'price' => '4000.00', 'priceCurrency' => 'ZAR', 'availability' => 'https://schema.org/InStock', 'url' => 'https://learning.example.test/bundles/garden', 'priceValidUntil' => '2026-12-31'], $node['offers']);
        self::assertArrayNotHasKey('offers', StructuredData::bundle(['offer' => null] + $bundle));
    }

    public function testTheHomePageNamesTheBusinessAndTheSite(): void
    {
        $organisation = StructuredData::organisation(['id' => 'https://learning.example.test/#organization', 'name' => 'Example Learning', 'legal_name' => 'Example Learning (Pty) Ltd', 'url' => 'https://learning.example.test/',
            'logo' => 'https://learning.example.test/img/favicon-512x512.png', 'email' => 'hello@example.test', 'telephone' => '+27 21 000 0000', 'address' => '1 Example Road, Cape Town, South Africa', 'country' => 'ZA',
            'contact_url' => 'https://learning.example.test/contact', 'same_as' => [], 'description' => '']);
        self::assertSame('Example Learning (Pty) Ltd', $organisation['legalName']);
        self::assertSame(['@type' => 'PostalAddress', 'streetAddress' => '1 Example Road, Cape Town, South Africa', 'addressCountry' => 'ZA'], $organisation['address']);
        self::assertSame('customer service', $organisation['contactPoint'][0]['contactType']);
        self::assertArrayNotHasKey('sameAs', $organisation, 'No profile, no sameAs: a platform\'s home page is not the business\'s profile.');
        $site = StructuredData::website('https://learning.example.test/#website', 'Example Learning', 'https://learning.example.test/', 'en-ZA', 'https://learning.example.test/#organization');
        self::assertSame(['@context' => 'https://schema.org', '@type' => 'WebSite', '@id' => 'https://learning.example.test/#website', 'name' => 'Example Learning', 'url' => 'https://learning.example.test/', 'inLanguage' => 'en-ZA', 'publisher' => ['@id' => 'https://learning.example.test/#organization']], $site);
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

    public function testACodeIsTakenBareOrFromItsWholeMetaTag(): void
    {
        self::assertSame('Ab12_cd-34EFgh56ijKL78mnOP90qrSTuv12wxYZ345', SearchSettings::code('  Ab12_cd-34EFgh56ijKL78mnOP90qrSTuv12wxYZ345 ', 'google-site-verification', 'The code'));
        self::assertSame('Ab12_cd-34EFgh56ijKL78mnOP90qrSTuv12wxYZ345', SearchSettings::code('<meta name="google-site-verification" content="Ab12_cd-34EFgh56ijKL78mnOP90qrSTuv12wxYZ345" />', 'google-site-verification', 'The code'));
        self::assertSame('0123456789ABCDEF0123456789ABCDEF', SearchSettings::code("<meta name='msvalidate.01' content='0123456789ABCDEF0123456789ABCDEF'>", 'msvalidate.01', 'The code'));
        self::assertSame('', SearchSettings::code('', 'msvalidate.01', 'The code'), 'Empty leaves it unset.');
        foreach (['<meta name="msvalidate.01" content="0123456789ABCDEF0123456789ABCDEF">' => 'google-site-verification', 'abc def ghi jkl' => 'msvalidate.01', '"><script>' => 'msvalidate.01', '<p>no tag</p>' => 'msvalidate.01'] as $typed => $meta) {
            $refused = false;
            try {
                SearchSettings::code($typed, $meta, 'The code');
            } catch (InvalidArgumentException) {
                $refused = true;
            }
            self::assertTrue($refused, 'Refused: ' . $typed);
        }
        $settings = SearchSettings::fixed(['ai_crawlers' => 'search', 'google' => 'Ab12_cd-34EFgh56ijKL78mnOP90qrSTuv12wxYZ345', 'bing' => '']);
        self::assertSame(['google-site-verification' => 'Ab12_cd-34EFgh56ijKL78mnOP90qrSTuv12wxYZ345'], $settings->verificationTags());
        self::assertSame(SearchSettings::SEARCH_ONLY, $settings->aiCrawlers());
        self::assertSame(SearchSettings::ALLOW_ALL, SearchSettings::fixed([])->aiCrawlers(), 'Every AI crawler is let in until it is saved: the owner\'s choice.');
    }

    public function testEachChoiceLetsInTheKindsItSays(): void
    {
        foreach ([AiCrawlers::TRAINING, AiCrawlers::SEARCH, AiCrawlers::USER] as $kind) {
            self::assertTrue(AiCrawlers::allowed($kind, SearchSettings::ALLOW_ALL));
            self::assertFalse(AiCrawlers::allowed($kind, SearchSettings::BLOCK_ALL));
        }
        self::assertFalse(AiCrawlers::allowed(AiCrawlers::TRAINING, SearchSettings::SEARCH_ONLY));
        self::assertTrue(AiCrawlers::allowed(AiCrawlers::SEARCH, SearchSettings::SEARCH_ONLY));
        self::assertTrue(AiCrawlers::allowed(AiCrawlers::USER, SearchSettings::SEARCH_ONLY));
        $all = AiCrawlers::all();
        foreach (['GPTBot' => AiCrawlers::TRAINING, 'OAI-SearchBot' => AiCrawlers::SEARCH, 'ChatGPT-User' => AiCrawlers::USER, 'ClaudeBot' => AiCrawlers::TRAINING, 'Claude-SearchBot' => AiCrawlers::SEARCH, 'Claude-User' => AiCrawlers::USER,
            'Google-Extended' => AiCrawlers::SEARCH, 'PerplexityBot' => AiCrawlers::SEARCH, 'Applebot-Extended' => AiCrawlers::TRAINING, 'meta-externalagent' => AiCrawlers::TRAINING, 'CCBot' => AiCrawlers::TRAINING] as $token => $kind) {
            self::assertSame($kind, $all[$token]['kind'] ?? null, $token);
        }
        foreach (['Googlebot', 'Bingbot', 'Applebot', 'facebookexternalhit', 'Twitterbot', 'LinkedInBot'] as $never) {
            self::assertArrayNotHasKey($never, $all, $never . ' is a search engine or a link preview, never kept out as AI.');
        }
        self::assertSame(count($all), array_sum(AiCrawlers::counts()));
    }

    public function testACoverIsSlicedIntoTheShareCanvasNeverStretched(): void
    {
        $sized = SharePictures::sized('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 360" preserveAspectRatio="xMidYMid meet"><rect width="640" height="360" fill="#0f3d3e"/></svg>');
        self::assertMatchesRegularExpression('/^<svg[^>]*\bwidth="1200"[^>]*\bheight="630"/', $sized);
        self::assertStringContainsString('preserveAspectRatio="xMidYMid slice"', $sized);
        self::assertStringContainsString('viewBox="0 0 640 360"', $sized);
        self::assertStringContainsString('viewBox="0 0 300 150"', SharePictures::sized('<svg xmlns="http://www.w3.org/2000/svg" width="300px" height="150"><rect width="300" height="150"/></svg>'), 'A cover without a viewBox gets its own size.');
        self::assertFalse(SharePictures::usable('<p>not a picture</p>'));
        self::assertNull(SharePictures::version(''));
        self::assertSame(16, strlen((string) SharePictures::version('<svg xmlns="http://www.w3.org/2000/svg"/>')));
    }

    public function testThePictureIsDrawnOnceAndStoredUnderItsCover(): void
    {
        $folder = sys_get_temp_dir() . '/share-pictures-test-' . bin2hex(random_bytes(4));
        $pictures = new SharePictures($folder);
        if (!$pictures->available()) self::markTestSkipped('rsvg-convert is not installed here.');
        $cover = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 360" preserveAspectRatio="xMidYMid slice"><defs><linearGradient id="g"><stop offset="0" stop-color="#15232b"/><stop offset="1" stop-color="#0f4231"/></linearGradient></defs><rect width="640" height="360" fill="url(#g)"/></svg>';
        try {
            self::assertFalse($pictures->has($cover));
            self::assertTrue($pictures->make($cover));
            self::assertTrue($pictures->has($cover));
            $size = getimagesizefromstring((string) $pictures->bytes($cover));
            self::assertNotFalse($size);
            self::assertSame([1200, 630, 'image/png'], [$size[0], $size[1], $size['mime']]);
            self::assertTrue($pictures->make($cover), 'Drawn already: nothing to do.');
            self::assertCount(1, glob($folder . '/share-pictures/*.png') ?: []);
            self::assertFalse($pictures->make('<svg'), 'A broken cover draws nothing and never throws.');
        } finally {
            foreach (glob($folder . '/share-pictures/*') ?: [] as $file) unlink($file);
            @rmdir($folder . '/share-pictures');
            @rmdir($folder);
        }
    }
}

<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Tests\Support\RenderHarness;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/** Uses the actual Symfony Twig service, including Stimulus and importmap extensions. */
final class ProfileImageUiRenderTest extends TestCase
{
    private static function dom(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        return new DOMXPath($dom);
    }

    #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]
    public function testProfileImageRendersExactStimulusActionsAndWorkingNativeUpload(): void
    {
        $profile = array_fill_keys(['first_name', 'middle_names', 'last_name', 'identification_number', 'birthdate', 'gender', 'mobile_number', 'display_name', 'certificate_name', 'full_name'], 'Example');
        $renderer = \CattoLearning\Application\CliBootstrap::boot()['container']->get(\CattoLearning\View\ThemeRenderer::class);
        $html = $renderer->renderFragment('partials/account/profile', RenderHarness::hiveWith(['profile' => $profile, 'profile_image_size' => 256, 'profile_image_url' => '/example.png']));
        $dom = self::dom($html);
        self::assertSame('change->profile-image#choose', $dom->evaluate('string(//input[@id="profile-image-file"]/@data-action)'));
        self::assertSame('input->profile-image#zoom', $dom->evaluate('string(//input[@id="profile-image-zoom"]/@data-action)'));
        self::assertSame('submit->profile-image#capture', $dom->evaluate('string(//form[@action="/account/profile/image"]/@data-action)'));
        self::assertSame(1.0, $dom->evaluate('count(//form[@enctype="multipart/form-data"]//input[@name="image" and @required])'));
        self::assertSame(1.0, $dom->evaluate('count(//*[@data-profile-image-target="editor" and @hidden])'));
        foreach (['file', 'zoom', 'data', 'stage', 'preview'] as $target) {
            self::assertSame(1.0, $dom->evaluate('count(//*[@data-profile-image-target="' . $target . '"])'), $target);
        }
        self::assertSame('profile-image-file-help', $dom->evaluate('string(//*[@id="profile-image-file"]/@aria-describedby)'));
        self::assertSame('profile-image-zoom-help', $dom->evaluate('string(//*[@id="profile-image-zoom"]/@aria-describedby)'));
        self::assertDoesNotMatchRegularExpression('/data-action="[^"\n]*(?:aria-|\{%)/', $html);
    }

}

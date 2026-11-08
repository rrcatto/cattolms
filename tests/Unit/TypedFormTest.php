<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Http\TypedForm;
use PHPUnit\Framework\TestCase;

/**
 * What a refused form sent is kept by the address it was sent to and read back only by the form sent there
 * (Http\TypedForm, 2026-11-07): never its token, long values cut short, lists kept, anything else dropped.
 */
final class TypedFormTest extends TestCase
{
    public function testWhatWasSentIsKeptWithoutTheFormsToken(): void
    {
        $kept = TypedForm::kept('/admin/courses', ['csrf' => 'secret-token', 'title' => 'Garden Design', 'list_ids' => ['3', '5'], 'width' => ['about' => 'half'], 7 => 'unnamed']);
        self::assertSame(['action' => '/admin/courses', 'values' => ['title' => 'Garden Design', 'list_ids' => ['3', '5'], 'width' => ['about' => 'half']]], $kept);
        self::assertSame('200000', (string) mb_strlen((string) TypedForm::kept('/x', ['body' => str_repeat('a', 200005)])['values']['body']), 'A very long value is cut short.');
        self::assertSame(['deep' => ['a' => ['b' => 'c']]], TypedForm::kept('/x', ['deep' => ['a' => ['b' => 'c', 'd' => ['too deep']]]])['values'], 'Two levels of lists are kept, no more.');
    }

    public function testOnlyTheFormSentToTheAddressReadsIt(): void
    {
        $kept = TypedForm::kept('/admin/courses/GDN-101/pricing', ['price' => 'lots']);
        self::assertSame(['price' => 'lots'], TypedForm::for($kept, '/admin/courses/GDN-101/pricing'));
        self::assertSame(['price' => 'lots'], TypedForm::for($kept, '/admin/courses/GDN-101/pricing?return=x#new'), 'The address is compared without its query or place.');
        self::assertSame([], TypedForm::for($kept, '/admin/courses/GDN-102/pricing'), 'Another form reads nothing.');
        self::assertSame([], TypedForm::for(null, '/admin/courses'), 'Nothing kept, nothing read.');
        self::assertSame([], TypedForm::for(['action' => 5, 'values' => []], '/admin/courses'));
        self::assertNull(TypedForm::valid('not kept values'));
    }
}

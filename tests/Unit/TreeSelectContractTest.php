<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards form.tree-select, the one category picker: a one-row control opening a native popover that
 * holds the hierarchy as nested lists, each option with a separate disclosure button for its
 * branch (shown by core script, which also closes every branch except those around the chosen
 * option whenever the list opens), options that submit their value (submit mode) or are radios
 * sent with the form's own Save (field mode), and the full path of the chosen option in the closed
 * control. Everything works without JavaScript: the branches are rendered open.
 */
final class TreeSelectContractTest extends TestCase
{
    /** The component is CattoPHP's, with its own tests there; the script that opens and closes it is still cattolms's. */
    public function testCoreScriptOpensTheChosenBranchAndClosesAFieldModeList(): void
    {
        $script = (string) (file_get_contents(\CattoLearning\Tests\Support\RenderHarness::root() . '/vendor/rrcatto/cattophp/public/js/cattophp.js') . file_get_contents(\CattoLearning\Tests\Support\RenderHarness::root() . '/public_html/js/platform-overrides.js'));
        foreach (["'.cl-ui-tree-select-expand'", "'beforetoggle'", "event.newState === 'open'", 'showChosen()', 'button.hidden = false', 'window.CattoTreeSelect', "select.dataset.treeSelect !== 'field'", 'hidePopover()', 'treeSelectPath'] as $contract) {
            self::assertStringContainsString($contract, $script);
        }
    }
}

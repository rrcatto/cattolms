<?php

declare(strict_types=1);

namespace CattoLearning\Commerce\Http;

use CattoLearning\Auth\AuthService;
use CattoLearning\Commerce\Settings\CommerceSettings;
use CattoLearning\Commerce\Settings\FeeTiers;
use CattoLearning\Commerce\Settings\ProcessorFee;
use CattoLearning\Http\Controller\BaseController;
use CattoLearning\Support\Duration;
use CattoLearning\Support\Money;
use CattoLearning\View\ThemeRenderer;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The commerce settings (owner, 2026-10-17), part of Administration > Settings since 2026-10-18: one
 * settings area, not two places. One accordion section per group, each saved on its own. Each field shows the value in force,
 * whether it comes from .env or was set here, and a reset to the .env default; durations are shown in
 * words beside their field. Fees are entered in rands and stored in cents.
 */
final class CommerceSettingsController extends BaseController
{
    private const TIER_ROWS = 6;

    public function __construct(AuthService $auth, ThemeRenderer $view, RequestStack $requests, private readonly CommerceSettings $settings)
    {
        parent::__construct($auth, $view, $requests);
    }

    /**
     * The commerce settings as Administration > Settings shows them, by section: each field with its
     * value in force, where it comes from and its .env default, and durations and fees in words.
     *
     * @return array<string,list<array<string,mixed>>>
     */
    public static function fieldsView(CommerceSettings $settings): array
    {
        $fields = [];
        foreach (CommerceSettings::SETTINGS as $key => [$section, $env, $default, $type]) {
            $text = $settings->text($key);
            $field = ['key' => $key, 'label' => CommerceSettings::label($key), 'text' => $text, 'env' => $env, 'default' => $settings->environmentText($key),
                'source' => $settings->source($key), 'type' => $type, 'words' => $type === 'duration' ? Duration::parse($text)->words() : '',
                // How to write a length of time, beside every field that takes one (owner, 2026-10-26).
                'how' => $type === 'duration' ? Duration::HOW : ''];
            if ($type === 'processor_fee') {
                $fee = ProcessorFee::fromText($text);
                $field['percent'] = $fee->percent;
                $field['fixed'] = self::rands($fee->fixedMinor);
                $field['words'] = $fee->percent . '% plus ' . Money::ofMinorUnits($fee->fixedMinor)->format();
            }
            if ($type === 'fee_tiers') {
                $tiers = FeeTiers::fromText($text)->tiers;
                $field['rows'] = [];
                for ($i = 0; $i < self::TIER_ROWS; $i++) $field['rows'][] = ['from' => isset($tiers[$i]) ? self::rands($tiers[$i]['from']) : '', 'fee' => isset($tiers[$i]) ? self::rands($tiers[$i]['fee']) : ''];
                $field['words'] = implode(', ', array_map(static fn(array $t): string => Money::ofMinorUnits($t['fee'])->format() . ' from ' . Money::ofMinorUnits($t['from'])->format(), $tiers));
            }
            $fields[$section][] = $field;
        }
        return $fields;
    }

    #[Route('/admin/settings/commerce/{section}', name: 'admin_commerce_settings_save', requirements: ['section' => 'fees|references|wallet|orders'], methods: ['POST'])]
    public function save(): Response
    {
        $this->requireCsrf();
        $actor = $this->requirePermission('SYSTEM.SETTING.MANAGE');
        $section = (string) $this->param('section');
        $back = '/admin/settings?section=commerce-' . $section . '#commerce-settings-' . $section;

        return $this->handle(function () use ($actor, $section, $back): void {
            $values = [];
            foreach (CommerceSettings::SETTINGS as $key => [$owner, , , $type]) {
                if ($owner !== $section) continue;
                $values[$key] = match ($type) {
                    'processor_fee' => trim($this->posted($key . '_percent')) . '%+' . self::cents($this->posted($key . '_fixed')),
                    'fee_tiers' => $this->tiersText($key),
                    default => $this->posted($key),
                };
            }
            try {
                $this->settings->saveSection($section, $values, $actor->id);
            } catch (InvalidArgumentException $exception) {
                throw new RuntimeException($exception->getMessage());
            }
            $this->flash('success', 'Commerce settings saved. Orders already placed keep the values they were placed with.');
            $this->redirect($back);
        }, $back);
    }

    #[Route('/admin/settings/commerce/reset/{key}', name: 'admin_commerce_settings_reset', requirements: ['key' => '[a-z_]+'], methods: ['POST'])]
    public function reset(): Response
    {
        $this->requireCsrf();
        $this->requirePermission('SYSTEM.SETTING.MANAGE');
        $key = (string) $this->param('key');
        $section = CommerceSettings::SETTINGS[$key][0] ?? 'fees';
        $back = '/admin/settings?section=commerce-' . $section . '#commerce-settings-' . $section;

        return $this->handle(function () use ($key, $back): void {
            try {
                $this->settings->reset($key);
            } catch (InvalidArgumentException $exception) {
                throw new RuntimeException($exception->getMessage());
            }
            $this->flash('success', CommerceSettings::label($key) . ' is back to its .env default.');
            $this->redirect($back);
        }, $back);
    }

    private function tiersText(string $key): string
    {
        $from = (array) ($_POST[$key . '_from'] ?? []);
        $fee = (array) ($_POST[$key . '_fee'] ?? []);
        $pairs = [];
        for ($i = 0; $i < self::TIER_ROWS; $i++) {
            $start = trim((string) ($from[$i] ?? ''));
            $amount = trim((string) ($fee[$i] ?? ''));
            if ($start === '' && $amount === '') continue;
            $pairs[] = self::cents($start === '' ? '0' : $start) . ':' . self::cents($amount === '' ? '0' : $amount);
        }

        return implode(',', $pairs);
    }

    private static function cents(string $rands): int
    {
        $rands = str_replace([' ', ','], '', trim($rands));
        if (!preg_match('/^\d{1,9}(?:\.\d{1,2})?$/', $rands)) throw new RuntimeException('Enter amounts in rands and cents, such as 20 or 1.50.');
        [$whole, $fraction] = array_pad(explode('.', $rands, 2), 2, '');

        return ((int) $whole) * 100 + (int) str_pad($fraction, 2, '0');
    }

    private static function rands(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}

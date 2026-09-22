<?php

namespace Paymenter\Extensions\Servers\Evorxa\Services;

use App\Helpers\ExtensionHelper;
use App\Models\Category;
use Paymenter\Extensions\Servers\Evorxa\Api\Client;
use Paymenter\Extensions\Servers\Evorxa\Support\Catalog;
use Paymenter\Extensions\Servers\Evorxa\Support\Lang;
use Paymenter\Extensions\Servers\Evorxa\Support\Settings;
use Paymenter\Extensions\Servers\Evorxa\Support\Util;

/**
 * Visual "operating system or app" picker on the checkout page (footer hook).
 *
 * The checkout keeps its normal dropdowns (checkoutConfig.os / checkoutConfig.app); orderform.js
 * draws logo cards on top of them and sets the real dropdowns, so validation and totals are untouched.
 */
class OrderForm
{
    public static function footer()
    {
        try {
            $route = request()->route();
            if (!$route || $route->getName() !== 'products.checkout' || Settings::get('picker') !== '1') {
                return [];
            }
            $category = $route->parameter('category');
            $slug = $route->parameter('product');
            $category = $category instanceof Category ? $category : Category::where('full_slug', $category)->orWhere('slug', $category)->first();
            $product = $category ? $category->products()->where('slug', is_object($slug) ? $slug->slug : $slug)->with(['server', 'settings'])->first() : null;
            if (!$product || !$product->server || $product->server->extension !== Client::MODULE) {
                return [];
            }
            $map = self::map($product);
            if (!$map['options']) {
                return [];
            }
            $assets = Panel::assetsUrl();
            $dir = Panel::assetsDir() . '/';
            $v = substr(md5(@filemtime($dir . 'orderform.js') . '|' . @filemtime($dir . 'orderform.css') . '|' . @filemtime($dir . 'paymenter.css')), 0, 8);
            $html = '<link rel="stylesheet" href="' . e($assets) . 'orderform.css?v=' . $v . '">'
                . '<link rel="stylesheet" href="' . e($assets) . 'paymenter.css?v=' . $v . '">'
                . '<script type="application/json" id="evx-of-map">'
                . json_encode($map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)
                . '</script>'
                . '<script src="' . e($assets) . 'orderform.js?v=' . $v . '" defer></script>';
            return ['view' => $html, 'priority' => 50];
        } catch (\Throwable $e) {
            report($e);
            return [];
        }
    }

    /** Field => type + choices (value => display data) for one product. */
    public static function map($product)
    {
        $settings = ExtensionHelper::settingsToArray($product->settings);
        $packageId = (int) (isset($settings['plan']) ? $settings['plan'] : 0);
        $options = [];
        if ($packageId) {
            $catalog = new Catalog(Client::forServer($product->server));
            $os = [];
            foreach ($catalog->osTemplates($packageId) as $template) {
                if (!$template['eol']) {
                    $os[$template['slug']] = self::osChoice($template['slug'], $template['label']);
                }
            }
            if ($os) {
                $options['os'] = ['type' => 'os', 'name' => 'checkoutConfig.os', 'choices' => $os];
            }
            $plan = $catalog->plan($packageId);
            if ($plan && Settings::feature('apps')) {
                $apps = ['none' => ['value' => 'none', 'title' => Lang::t('of_none'), 'sub' => '', 'logo' => '']];
                foreach ($catalog->appsFor($plan) as $slug => $app) {
                    $apps[$slug] = [
                        'value' => $slug,
                        'title' => Util::clean($app['name'], 60),
                        'sub' => Util::clean($app['tagline'], 80),
                        'logo' => Panel::logo('apps', $slug),
                    ];
                }
                if (count($apps) > 1) {
                    $options['app'] = ['type' => 'app', 'name' => 'checkoutConfig.app', 'choices' => $apps];
                }
            }
        }
        $t = Lang::load();
        $pick = function ($k, $fallback) use ($t) {
            return isset($t[$k]) ? $t[$k] : $fallback;
        };
        return [
            'options' => $options,
            't' => [
                'version' => $pick('of_version', 'Version'),
                'none' => $pick('of_none', 'No app'),
                'noneSub' => $pick('of_none_sub', 'Just the operating system'),
                'appNote' => $pick('of_app_note', 'The selected app installs its own system, so the operating system choice is not used.'),
                'title' => $pick('of_title', 'Operating system or app'),
                'modeOs' => $pick('of_mode_os', 'Operating system'),
                'modeApp' => $pick('of_mode_app', 'One-click app'),
                'versions' => $pick('of_versions', ':n versions'),
                'appHint' => $pick('of_app_hint', 'Pick an app. It comes with its own operating system, installed and ready to use.'),
            ],
        ];
    }

    /** ubuntu-24.04 + "Ubuntu Server 24.04 LTS (Noble Numbat)" => family Ubuntu, title "24.04 LTS", sub "Noble Numbat". */
    private static function osChoice($slug, $label)
    {
        $family = strtok($slug, '-');
        $name = preg_split('/\s+/', $label);
        $familyName = $name ? $name[0] : ucfirst($family);
        $sub = '';
        if (preg_match('/\(([^)]*)\)/', $label, $m)) {
            $sub = $m[1];
        }
        $title = trim(preg_replace('/\([^)]*\)/', '', $label));
        $title = trim(preg_replace('/^' . preg_quote($familyName, '/') . '(\s+Server)?\s*/i', '', $title));
        return [
            'value' => $slug,
            'family' => $family,
            'familyName' => Util::clean($familyName, 40),
            'title' => Util::clean($title !== '' ? $title : $label, 60),
            'sub' => Util::clean($sub, 60),
            'logo' => Panel::logo('os', Panel::osFamily($familyName)),
        ];
    }
}

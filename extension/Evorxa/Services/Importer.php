<?php

namespace Paymenter\Extensions\Servers\Evorxa\Services;

use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Paymenter\Extensions\Servers\Evorxa\Api\Client;
use Paymenter\Extensions\Servers\Evorxa\Support\Cache;
use Paymenter\Extensions\Servers\Evorxa\Support\Catalog;
use Paymenter\Extensions\Servers\Evorxa\Support\Repo;
use Paymenter\Extensions\Servers\Evorxa\Support\Util;

/**
 * Creates Paymenter products from Evorxa plans: plans and prices (every currency) with your markup,
 * the Evorxa server settings, stock and upgrade paths. The OS / app / hostname choices come from the
 * extension at checkout, so nothing else has to be set up. Re-running skips plans already in the category.
 */
class Importer
{
    /** Paymenter plan => Evorxa price used as the base. */
    const CYCLES = [
        'monthly' => ['upstream' => 'monthly', 'name' => 'Monthly', 'period' => 1, 'unit' => 'month'],
        'semiannually' => ['upstream' => '6months', 'name' => 'Every 6 months', 'period' => 6, 'unit' => 'month'],
        'annually' => ['upstream' => 'yearly', 'name' => 'Yearly', 'period' => 1, 'unit' => 'year'],
    ];

    private $api;
    private $catalog;
    private $server;

    public function __construct(Server $server)
    {
        $this->server = $server;
        $this->api = Client::forServer($server);
        $this->catalog = new Catalog($this->api);
    }

    /**
     * @param array $opts packages[], category_id, new_category, markup (%), rounding (99|whole|none),
     *                    cycles[] (monthly|semiannually|annually), hidden (bool)
     * @return array ['category' => id, 'rows' => [plan, status (created|skipped|error), product_id, name, prices, message]]
     */
    public function run(array $opts)
    {
        Mailer::install();
        $plans = $this->catalog->plans(true);
        $category = $this->category($opts);
        $markup = max(-50, min(1000, (float) (isset($opts['markup']) ? $opts['markup'] : 0)));
        $rounding = isset($opts['rounding']) ? (string) $opts['rounding'] : '99';
        $cycles = array_values(array_intersect(array_keys(self::CYCLES), (array) (isset($opts['cycles']) ? $opts['cycles'] : [])));
        if (!$cycles) {
            $cycles = ['monthly'];
        }
        $currencies = $this->currencies();
        $sort = (int) Product::where('category_id', $category->id)->max('sort');
        $report = [];

        foreach ((array) (isset($opts['packages']) ? $opts['packages'] : []) as $packageId) {
            $packageId = (int) $packageId;
            if (!isset($plans[$packageId])) {
                $report[] = ['plan' => '#' . $packageId, 'status' => 'error', 'message' => 'Plan not found upstream'];
                continue;
            }
            $plan = $plans[$packageId];
            $name = self::productName($plan);
            $existing = self::productsFor($packageId)->where('category_id', $category->id)->first();
            if ($existing) {
                $report[] = ['plan' => $name, 'status' => 'skipped', 'product_id' => $existing->id, 'name' => $existing->name, 'message' => 'Already in this category'];
                continue;
            }
            try {
                $shown = [];
                DB::transaction(function () use ($plan, $packageId, $name, $category, $opts, $cycles, $currencies, $markup, $rounding, &$sort, &$shown, &$product) {
                    $product = Product::create([
                        'category_id' => $category->id,
                        'name' => $name,
                        'slug' => self::uniqueSlug($name),
                        'description' => self::description($plan, $this->offersWindows($packageId)),
                        'stock' => max(0, (int) (isset($plan['stock']) ? $plan['stock'] : 0)),
                        'server_id' => $this->server->id,
                        'hidden' => !empty($opts['hidden']),
                        'allow_quantity' => 'disabled',
                        'sort' => min(255, ++$sort),
                    ]);
                    foreach (['plan' => (string) $packageId, 'default_os' => $this->defaultOs($packageId), 'upstream_term' => 'match'] as $key => $value) {
                        $product->settings()->updateOrCreate(['key' => $key], ['value' => $value, 'type' => 'string']);
                    }
                    foreach ($cycles as $cycle) {
                        $meta = self::CYCLES[$cycle];
                        $upstream = isset($plan['pricing'][$meta['upstream']]['total']) ? (float) $plan['pricing'][$meta['upstream']]['total'] : null;
                        if ($upstream === null) {
                            continue;
                        }
                        $paymenterPlan = $product->plans()->create([
                            'name' => $meta['name'],
                            'type' => 'recurring',
                            'billing_period' => $meta['period'],
                            'billing_unit' => $meta['unit'],
                        ]);
                        foreach ($currencies as $code => $currency) {
                            $price = self::price($upstream * (1 + $markup / 100) * $currency['factor'], $rounding);
                            $paymenterPlan->prices()->create(['currency_code' => $code, 'price' => $price, 'setup_fee' => 0]);
                            if ($currency['default']) {
                                $shown[$cycle] = $currency['prefix'] . number_format($price, 2) . $currency['suffix'];
                            }
                        }
                    }
                });
                $report[] = ['plan' => $name, 'status' => 'created', 'product_id' => $product->id, 'name' => $name, 'prices' => $shown, 'message' => 'Stock ' . (int) (isset($plan['stock']) ? $plan['stock'] : 0)];
            } catch (\Throwable $e) {
                $report[] = ['plan' => $name, 'status' => 'error', 'message' => $e->getMessage()];
            }
        }
        $this->linkUpgrades($plans);
        Repo::log(null, 'import', true, 'Imported ' . count(array_filter($report, function ($r) {
            return $r['status'] === 'created';
        })) . ' plan(s) into category "' . $category->name . '" with ' . $markup . '% markup');
        return ['category' => $category->id, 'rows' => $report];
    }

    /** Products (of any category) selling one Evorxa package. */
    public static function productsFor($packageId)
    {
        $serverIds = Server::where('extension', Client::MODULE)->pluck('id')->all();
        return Product::whereIn('server_id', $serverIds)->whereHas('settings', function ($q) use ($packageId) {
            $q->where('key', 'plan')->where('value', (string) $packageId);
        });
    }

    public static function productName(array $plan)
    {
        $category = trim(preg_replace('/\s+VMs?$/i', '', isset($plan['category_name']) ? $plan['category_name'] : ''));
        return trim($category . ' ' . $plan['name']);
    }

    /** Customer-facing price rounding: 99 => next x.99, whole => next integer, none => cents. */
    public static function price($value, $rounding)
    {
        if ($rounding === '99') {
            return round(ceil($value + 0.01) - 0.01, 2);
        }
        if ($rounding === 'whole') {
            return (float) ceil($value);
        }
        return round($value, 2);
    }

    public static function description(array $plan, $windows = false)
    {
        $items = [
            (int) $plan['vcpu'] . ' vCPU cores',
            (int) $plan['ram'] . ' GB RAM',
            (int) $plan['storage'] . ' GB NVMe SSD storage',
        ];
        if (!empty($plan['uplink'])) {
            $items[] = e($plan['uplink']) . ' network port';
        }
        $items[] = 'Always-on DDoS protection';
        $items[] = 'Full root access, ready in minutes';
        $items[] = $windows ? 'Linux, Windows or one-click apps' : 'Linux or one-click apps';
        return '<ul><li>' . implode('</li><li>', $items) . '</li></ul>';
    }

    public function offersWindows($packageId)
    {
        foreach ($this->catalog->osTemplates($packageId) as $os) {
            if ($os['type'] === 'windows' && !$os['eol']) {
                return true;
            }
        }
        return false;
    }

    private function defaultOs($packageId)
    {
        $first = '';
        foreach ($this->catalog->osTemplates($packageId) as $os) {
            if ($os['slug'] === 'ubuntu-24.04') {
                return $os['slug'];
            }
            if (!$first && !$os['eol'] && $os['type'] === 'linux') {
                $first = $os['slug'];
            }
        }
        return $first;
    }

    private function category(array $opts)
    {
        if (!empty($opts['category_id']) && ($category = Category::find((int) $opts['category_id']))) {
            return $category;
        }
        $name = trim(isset($opts['new_category']) ? (string) $opts['new_category'] : '') ?: 'Cloud VPS';
        $slug = Str::slug($name) ?: 'cloud-vps';
        $base = $slug;
        for ($i = 2; Category::where('slug', $slug)->exists(); $i++) {
            $slug = $base . '-' . $i;
        }
        return Category::create([
            'name' => $name,
            'slug' => $slug,
            'description' => 'High-performance NVMe cloud servers, deployed in minutes with DDoS protection included.',
        ]);
    }

    private static function uniqueSlug($name)
    {
        $slug = Str::slug($name) ?: 'server';
        $base = $slug;
        for ($i = 2; Product::where('slug', $slug)->exists(); $i++) {
            $slug = $base . '-' . $i;
        }
        return $slug;
    }

    /**
     * Paymenter currencies with a conversion factor from USD (Evorxa's public exchange rates).
     * Currencies without a known rate are skipped (set their prices by hand).
     */
    private function currencies()
    {
        $default = strtoupper((string) config('settings.default_currency', 'USD'));
        $rates = Cache::remember('fx', 21600, function () {
            try {
                $raw = (new Client(''))->getPublic('catalog/exchange-rates');
                $rates = isset($raw['rates']) && is_array($raw['rates']) ? $raw['rates'] : (is_array($raw) ? $raw : []);
                return array_change_key_case(array_map('floatval', array_filter($rates, 'is_numeric')), CASE_UPPER);
            } catch (\Throwable $e) {
                return [];
            }
        });
        $list = [];
        foreach (Currency::all() as $currency) {
            $code = strtoupper($currency->code);
            $factor = $code === 'USD' ? 1.0 : (isset($rates[$code]) && $rates[$code] > 0 ? (float) $rates[$code] : null);
            if ($factor === null) {
                continue;
            }
            $list[$code] = ['factor' => $factor, 'prefix' => (string) $currency->prefix, 'suffix' => (string) $currency->suffix, 'default' => $code === $default];
        }
        return $list;
    }

    /** Upgrades only to bigger plans of the same family (Evorxa cannot shrink disks). */
    private function linkUpgrades(array $plans)
    {
        $serverIds = Server::where('extension', Client::MODULE)->pluck('id')->all();
        $products = Product::with('settings')->whereIn('server_id', $serverIds)->get();
        $packageOf = [];
        foreach ($products as $product) {
            $packageOf[$product->id] = (int) optional($product->settings->firstWhere('key', 'plan'))->value;
        }
        foreach ($products as $from) {
            $a = isset($plans[$packageOf[$from->id]]) ? $plans[$packageOf[$from->id]] : null;
            if (!$a) {
                continue;
            }
            foreach ($products as $to) {
                $b = isset($plans[$packageOf[$to->id]]) ? $plans[$packageOf[$to->id]] : null;
                if (!$b || $from->id === $to->id || $a['category'] !== $b['category']) {
                    continue;
                }
                $bigger = $b['ram'] >= $a['ram'] && $b['storage'] >= $a['storage'] && $b['vcpu'] >= $a['vcpu']
                    && ($b['ram'] > $a['ram'] || $b['storage'] > $a['storage'] || $b['vcpu'] > $a['vcpu']);
                if ($bigger && !DB::table('product_upgrades')->where('product_id', $from->id)->where('upgrade_id', $to->id)->exists()) {
                    DB::table('product_upgrades')->insert([
                        'product_id' => $from->id, 'upgrade_id' => $to->id,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }
    }
}

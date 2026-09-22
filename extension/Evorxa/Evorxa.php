<?php

namespace Paymenter\Extensions\Servers\Evorxa;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Models\Product;
use App\Models\Service;
use Exception;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Paymenter\Extensions\Servers\Evorxa\Api\ApiException;
use Paymenter\Extensions\Servers\Evorxa\Api\Client;
use Paymenter\Extensions\Servers\Evorxa\Services\Keeper;
use Paymenter\Extensions\Servers\Evorxa\Services\OrderForm;
use Paymenter\Extensions\Servers\Evorxa\Services\Panel;
use Paymenter\Extensions\Servers\Evorxa\Services\Provisioner;
use Paymenter\Extensions\Servers\Evorxa\Services\Watcher;
use Paymenter\Extensions\Servers\Evorxa\Support\Catalog;
use Paymenter\Extensions\Servers\Evorxa\Support\Lang;
use Paymenter\Extensions\Servers\Evorxa\Support\Repo;
use Paymenter\Extensions\Servers\Evorxa\Support\Schema;
use Paymenter\Extensions\Servers\Evorxa\Support\Settings;

/**
 * Evorxa Cloud for Paymenter: resell Evorxa VPS and VDS servers.
 *
 * Paymenter entry points only; the work is done in Services/ (same logic and money-safety
 * rules as the Evorxa WHMCS module).
 */
#[ExtensionMeta(
    name: 'Evorxa Cloud',
    description: 'Resell Evorxa VPS and VDS servers: automatic provisioning, a full client control panel (power, rDNS, graphs, DDoS, firewall, reinstall with OS or one-click apps) and plan import with your markup.',
    version: '1.0.0',
    author: 'ScaleBit Technologies',
    url: 'https://github.com/ScaleBit-Technologies/evorxa-paymenter',
    icon: 'https://raw.githubusercontent.com/ScaleBit-Technologies/evorxa-paymenter/master/marketplace/icon.png'
)]
class Evorxa extends Server
{
    const TOKEN_ABILITIES = ['instances:read', 'instances:write', 'analytics:read', 'shield:read', 'shield:write', 'wallet:read'];

    // ------------------------------------------------------------------ configuration

    /** Admin > Servers: connection only. Everything else is in Admin > Evorxa > Settings. */
    public function getConfig($values = []): array
    {
        return [
            [
                'name' => 'host',
                'type' => 'text',
                'label' => 'API hostname',
                'default' => 'api.evorxa.com',
                'description' => 'Leave as api.evorxa.com.',
                'required' => false,
            ],
            [
                'name' => 'token',
                'type' => 'password',
                'label' => 'API token',
                'description' => 'Evorxa console > API tokens. Abilities: ' . implode(' ', self::TOKEN_ABILITIES),
                'required' => true,
                'encrypted' => true,
            ],
        ];
    }

    public function testConfig(): bool|string
    {
        try {
            $api = Client::fromConfig($this->config);
            $me = $api->get('me');
            $email = isset($me['user']['email']) ? $me['user']['email'] : 'unknown';
            $missing = [];
            $tokenId = $api->tokenId();
            if ($tokenId) {
                foreach ((array) $api->get('me/api-tokens') as $token) {
                    if ((int) (isset($token['id']) ? $token['id'] : 0) !== $tokenId) {
                        continue;
                    }
                    $abilities = isset($token['abilities']) ? (array) $token['abilities'] : [];
                    if (!in_array('*', $abilities, true)) {
                        foreach (self::TOKEN_ABILITIES as $need) {
                            if (!in_array($need, $abilities, true)) {
                                $missing[] = $need;
                            }
                        }
                    }
                }
            }
            if ($missing) {
                return 'Connected as ' . $email . ', but the token is missing: ' . implode(', ', $missing) . '.';
            }
            Schema::ensure();
            return true;
        } catch (ApiException $e) {
            return $e->friendly();
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /** Product > Server settings. */
    public function getProductConfig($values = []): array
    {
        $plans = [];
        $os = [];
        try {
            $catalog = new Catalog(Client::fromConfig($this->config));
            foreach ($catalog->plans(true) as $id => $plan) {
                $plans[$id] = Catalog::planLabel($plan);
            }
            $os = $catalog->osUnion();
        } catch (\Throwable $e) {
            throw new Exception('Could not load Evorxa plans: ' . ($e instanceof ApiException ? $e->friendly() : $e->getMessage()));
        }

        return [
            [
                'name' => 'plan',
                'type' => 'select',
                'label' => 'Evorxa plan',
                'description' => 'The Evorxa plan this product sells.',
                'required' => true,
                'options' => $plans,
            ],
            [
                'name' => 'default_os',
                'type' => 'select',
                'label' => 'Default operating system',
                'description' => 'Pre-selected at checkout, and used when an order has no OS choice.',
                'required' => false,
                'default' => isset($os['ubuntu-24.04']) ? 'ubuntu-24.04' : null,
                'options' => $os,
            ],
            [
                'name' => 'upstream_term',
                'type' => 'select',
                'label' => 'Upstream billing term',
                'description' => 'How Evorxa bills your wallet for this server.',
                'default' => 'match',
                'required' => true,
                'options' => [
                    'match' => 'Match the client\'s term (cheapest upstream price)',
                    'monthly' => 'Always monthly upstream',
                ],
            ],
        ];
    }

    /** Checkout: operating system or one-click app, and an optional hostname. */
    public function getCheckoutConfig(Product $product, $values = [], $settings = []): array
    {
        $packageId = (int) (isset($settings['plan']) ? $settings['plan'] : 0);
        if (!$packageId) {
            return [];
        }
        $catalog = new Catalog(Client::fromConfig($this->config));
        $plan = $catalog->plan($packageId);
        $os = [];
        foreach ($catalog->osTemplates($packageId) as $template) {
            if (!$template['eol']) {
                $os[$template['slug']] = $template['label'];
            }
        }
        $default = isset($settings['default_os']) && isset($os[$settings['default_os']]) ? $settings['default_os'] : array_key_first($os);
        $config = [
            [
                'name' => 'os',
                'type' => 'select',
                'label' => Lang::t('order_os'),
                'required' => true,
                'default' => $default,
                'options' => $os,
            ],
        ];
        if ($plan && Settings::feature('apps')) {
            $apps = ['none' => Lang::t('order_app_none')];
            foreach ($catalog->appsFor($plan) as $slug => $app) {
                $apps[$slug] = $app['name'];
            }
            if (count($apps) > 1) {
                $config[] = [
                    'name' => 'app',
                    'type' => 'select',
                    'label' => Lang::t('order_app'),
                    'required' => false,
                    'default' => 'none',
                    'options' => $apps,
                ];
            }
        }
        $config[] = [
            'name' => 'hostname',
            'type' => 'text',
            'label' => Lang::t('order_hostname'),
            'placeholder' => 'server1',
            'description' => Lang::t('order_hostname_hint'),
            'required' => false,
            'validation' => ['nullable', 'max:253', 'regex:/^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/'],
        ];
        return $config;
    }

    // ------------------------------------------------------------------ lifecycle

    public function createServer(Service $service, $settings, $properties)
    {
        $this->run($service, $settings, $properties, fn (Provisioner $p) => $p->create());
        $row = Repo::find($service->id);
        return ['hostname' => $row ? $row->hostname : null, 'status' => $row ? $row->state : null];
    }

    public function suspendServer(Service $service, $settings, $properties)
    {
        return $this->run($service, $settings, $properties, fn (Provisioner $p) => $p->suspend());
    }

    public function unsuspendServer(Service $service, $settings, $properties)
    {
        return $this->run($service, $settings, $properties, fn (Provisioner $p) => $p->unsuspend());
    }

    public function terminateServer(Service $service, $settings, $properties)
    {
        return $this->run($service, $settings, $properties, fn (Provisioner $p) => $p->terminate());
    }

    public function upgradeServer(Service $service, $settings, $properties)
    {
        return $this->run($service, $settings, $properties, fn (Provisioner $p) => $p->changePackage());
    }

    /** Runs a Provisioner action; anything but "success" becomes an exception Paymenter shows to the admin. */
    public function run(Service $service, $settings, $properties, callable $fn)
    {
        try {
            $provisioner = new Provisioner($service, (array) $settings, (array) $properties, Client::fromConfig($this->config));
            $result = $fn($provisioner);
        } catch (ApiException $e) {
            $result = $e->friendly();
        }
        if ($result !== 'success') {
            throw new Exception($result);
        }
        return true;
    }

    // ------------------------------------------------------------------ client area

    public function getActions(Service $service, $settings = [], $properties = []): array
    {
        return [
            ['type' => 'view', 'name' => 'server', 'label' => Lang::t('tab_server'), 'function' => 'getPanel'],
        ];
    }

    public function getPanel(Service $service, $settings = [], $properties = [], $view = null)
    {
        try {
            return view('evorxa::panel', ['evx' => Panel::build($service)])->render();
        } catch (\Throwable $e) {
            report($e);
            return view('evorxa::error', ['t' => Lang::load()])->render();
        }
    }

    // ------------------------------------------------------------------ install / upgrade

    /** Zip upload in Admin > Extensions: create the tables and the "server ready" notification. */
    public function installed()
    {
        Schema::migrate();
        \Paymenter\Extensions\Servers\Evorxa\Services\Mailer::install();
    }

    public function upgraded($oldVersion = null)
    {
        Schema::migrate();
        \Paymenter\Extensions\Servers\Evorxa\Services\Mailer::install();
    }

    /** Tables are kept on purpose: they map paid services to running servers. */
    public function uninstalled() {}

    // ------------------------------------------------------------------ boot

    public function boot()
    {
        View::addNamespace('evorxa', __DIR__ . '/resources/views');

        if (!Route::has('evorxa.api')) {
            require __DIR__ . '/routes/web.php';
        }

        // Background work: finish builds, adopt unconfirmed orders, sync, stock, wallet alerts.
        if (app()->runningInConsole()) {
            Artisan::command('evorxa:tick', function () {
                Watcher::tick();
            })->purpose('Evorxa: finish provisioning, sync servers, stock and wallet checks');
            app()->booted(function () {
                app(Schedule::class)->command('evorxa:tick')->everyMinute()->withoutOverlapping(10)->runInBackground();
            });
        }

        // Renewal paid or cancellation requested: run the renewal keeper right away (queued).
        Event::listen(\App\Events\Invoice\Paid::class, function ($event) {
            $ids = [];
            foreach ($event->invoice->items as $item) {
                if ($item->reference_type === Service::class && $item->reference_id) {
                    $ids[] = (int) $item->reference_id;
                }
            }
            if ($ids) {
                dispatch(function () use ($ids) {
                    foreach ($ids as $id) {
                        if (Repo::find($id)) {
                            try {
                                Keeper::forService($id);
                            } catch (\Throwable $e) {
                                Repo::log($id, 'keeper', false, $e->getMessage());
                            }
                        }
                    }
                })->delay(now()->addSeconds(30));
            }
        });
        Event::listen(\App\Events\ServiceCancellation\Created::class, function ($event) {
            $id = (int) $event->cancellation->service_id;
            dispatch(function () use ($id) {
                if (Repo::find($id)) {
                    try {
                        Keeper::forService($id);
                    } catch (\Throwable $e) {
                        Repo::log($id, 'keeper', false, $e->getMessage());
                    }
                }
            });
        });

        // Visual "operating system or app" picker on the checkout page.
        Event::listen('footer', function () {
            return OrderForm::footer();
        });
    }
}

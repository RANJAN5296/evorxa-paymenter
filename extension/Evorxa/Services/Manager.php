<?php

namespace Paymenter\Extensions\Servers\Evorxa\Services;

use App\Helpers\ExtensionHelper;
use App\Models\Category;
use App\Models\NotificationTemplate;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Evorxa\Api\ApiException;
use Paymenter\Extensions\Servers\Evorxa\Api\Client;
use Paymenter\Extensions\Servers\Evorxa\Support\Cache;
use Paymenter\Extensions\Servers\Evorxa\Support\Catalog;
use Paymenter\Extensions\Servers\Evorxa\Support\Repo;
use Paymenter\Extensions\Servers\Evorxa\Support\Schema;
use Paymenter\Extensions\Servers\Evorxa\Support\Settings;
use Paymenter\Extensions\Servers\Evorxa\Support\Util;

/**
 * Data and actions behind Admin > Evorxa (dashboard, plans & import, servers, activity log, settings).
 * Every method returns plain arrays for the Blade view.
 */
class Manager
{
    const FEATURE_LABELS = [
        'power' => 'Start, restart, shut down, force off',
        'password' => 'Reset root password',
        'reinstall' => 'Reinstall operating system',
        'apps' => 'Reinstall with one-click apps',
        'graphs' => 'Usage graphs (CPU, memory, disk, network)',
        'ddos' => 'DDoS protection view and attack email alerts',
        'firewall' => 'Network firewall rules',
        'rdns' => 'Edit reverse DNS (rDNS) of the server\'s IP addresses',
    ];

    public static function server()
    {
        return Client::defaultServer();
    }

    public static function api()
    {
        return Client::forDefaultServer();
    }

    public static function evorxaProducts()
    {
        $serverIds = Server::where('extension', Client::MODULE)->pluck('id')->all();
        return Product::with(['settings', 'plans.prices'])->whereIn('server_id', $serverIds)->get();
    }

    // ------------------------------------------------------------------ dashboard

    public static function dashboard()
    {
        Schema::ensure();
        $server = self::server();
        $data = ['server' => $server ? ['id' => $server->id, 'name' => $server->name] : null, 'account' => null, 'error' => null];
        $checks = [];
        $checks[] = ['ok' => (bool) $server, 'label' => 'Evorxa server added', 'hint' => 'Admin > Servers > New server, extension "Evorxa", paste your API token.', 'url' => self::url('filament.admin.resources.servers.index')];

        if ($server) {
            try {
                // Cached for a minute so the dashboard opens instantly; "Check wallet" / "Sync now" refresh it.
                $data['account'] = Cache::remember('dash:account', 60, function () {
                    $api = self::api();
                    $me = (new Catalog($api))->me(true);
                    $balance = $api->get('wallet/balance');
                    $cents = isset($balance['balance_cents']) ? (int) $balance['balance_cents'] : 0;
                    $renewal = isset($me['user']['next_renewal']) && is_array($me['user']['next_renewal']) ? $me['user']['next_renewal'] : null;
                    return [
                        'email' => isset($me['user']['email']) ? $me['user']['email'] : '',
                        'balance' => Util::formatCents($cents),
                        'low' => $cents < (float) Settings::get('low_balance') * 100,
                        'renewalAmount' => $renewal ? Util::formatCents($renewal['amount_cents']) : 'Nothing due',
                        'renewalWhen' => $renewal ? 'Due ' . Util::date($renewal['at']) . ' · ' . (int) $renewal['instances'] . ' server(s) on the account' : '',
                        'autoRenew' => !isset($me['user']['auto_renew']) || !empty($me['user']['auto_renew']),
                    ];
                });
            } catch (ApiException $e) {
                $data['error'] = $e->friendly();
            }
        }
        $projects = [];
        try {
            $projects = $server ? (new Catalog(self::api()))->ownedProjects() : [];
        } catch (\Throwable $e) {
        }
        $pid = Settings::projectId();
        $checks[] = ['ok' => $pid > 0, 'label' => 'Project for new servers: ' . ($pid ? (isset($projects[$pid]) ? $projects[$pid] . ' (#' . $pid . ')' : '#' . $pid) : 'not chosen'), 'hint' => 'Tip: create a dedicated project (e.g. "Resale") in the Evorxa dashboard so client servers stay apart from your own.', 'tab' => 'settings'];
        $suffix = Settings::get('hostname_suffix');
        $checks[] = ['ok' => $suffix !== '', 'label' => 'Hostname suffix: ' . ($suffix ?: 'not set'), 'hint' => 'Client servers get hostnames like vps123.' . ($suffix ?: 'example.com') . ' (also used as reverse DNS).', 'tab' => 'settings'];
        $products = self::evorxaProducts();
        $visible = $products->where('hidden', false)->count();
        $checks[] = ['ok' => count($products) > 0, 'label' => count($products) . ' Evorxa product(s), ' . $visible . ' visible in the store', 'hint' => 'Import plans with your markup, then review and unhide them in Admin > Products.', 'tab' => 'plans'];
        $lastRun = strtotime((string) DB::table('settings')->where('key', 'last_scheduler_run')->orderByDesc('updated_at')->value('value'));
        $cronOk = $lastRun && time() - $lastRun < 5 * 60;
        $checks[] = ['ok' => $cronOk, 'label' => 'Scheduler ran ' . ($lastRun ? self::ago($lastRun) : 'never'), 'hint' => 'Run "php artisan schedule:run" every minute (cron) and keep the queue worker running, so new servers are finished and emailed promptly.', 'url' => self::url('filament.admin.pages.cron-stats')];
        $systemEmail = (string) config('settings.system_email_address');
        $checks[] = ['ok' => $systemEmail !== '', 'label' => 'Alert address: ' . ($systemEmail ?: 'not set'), 'hint' => 'Wallet and build alerts go to the System Email Address (Admin > Settings > Mail).', 'url' => self::url('filament.admin.pages.settings')];
        $data['checks'] = $checks;
        $done = count(array_filter($checks, function ($c) {
            return $c['ok'];
        }));
        $data['progress'] = ['done' => $done, 'total' => count($checks), 'pct' => (int) round(100 * $done / max(1, count($checks)))];
        $data['finance'] = self::finance();
        $counts = [];
        foreach (DB::table(Schema::SERVICES)->select('state', DB::raw('COUNT(*) AS c'))->groupBy('state')->get() as $row) {
            $counts[$row->state] = (int) $row->c;
        }
        $data['counts'] = $counts;
        $data['attention'] = self::attention();
        $data['recent'] = self::logRows(DB::table(Schema::LOG)->orderBy('id', 'desc')->limit(10)->get());
        return $data;
    }

    /**
     * Monthly economics of active Evorxa services in the default currency:
     * what clients pay (normalised per month) versus what Evorxa charges for their plans.
     */
    public static function finance()
    {
        $currency = strtoupper((string) config('settings.default_currency', 'USD'));
        $rows = DB::table(Schema::SERVICES . ' as e')
            ->join('services as s', 's.id', '=', 'e.service_id')
            ->leftJoin('plans as p', 'p.id', '=', 's.plan_id')
            ->where('e.state', 'active')->where('s.status', 'active')
            ->get(['s.price', 's.currency_code', 'p.billing_period', 'p.billing_unit', 'e.package_id', 'e.upstream_cycle']);
        if (!count($rows)) {
            return ['count' => 0];
        }
        try {
            $plans = (new Catalog())->plans();
        } catch (\Throwable $e) {
            $plans = [];
        }
        $fx = Cache::get('fx') ?: [];
        $toDefault = function ($amount, $code) use ($fx, $currency) {
            $code = strtoupper((string) $code);
            if ($code === $currency) {
                return $amount;
            }
            $from = $code === 'USD' ? 1.0 : (isset($fx[$code]) ? (float) $fx[$code] : 0);
            $to = $currency === 'USD' ? 1.0 : (isset($fx[$currency]) ? (float) $fx[$currency] : 0);
            return $from > 0 && $to > 0 ? $amount / $from * $to : $amount;
        };
        $revenue = 0.0;
        $cost = 0.0;
        $costKnown = true;
        foreach ($rows as $r) {
            $months = $r->billing_unit === 'year' ? 12 * (int) $r->billing_period : ($r->billing_unit === 'month' ? (int) $r->billing_period : 0);
            if ($months > 0) {
                $revenue += $toDefault((float) $r->price / $months, $r->currency_code);
            }
            $cycle = $r->upstream_cycle ?: 'monthly';
            if (isset($plans[$r->package_id]['pricing'][$cycle]['per_month'])) {
                $cost += $toDefault((float) $plans[$r->package_id]['pricing'][$cycle]['per_month'], 'USD');
            } else {
                $costKnown = false;
            }
        }
        $fmt = function ($v) use ($currency) {
            return number_format($v, 2) . ' ' . $currency;
        };
        return [
            'count' => count($rows),
            'revenue' => $fmt($revenue),
            'cost' => $fmt($cost),
            'margin' => $fmt($revenue - $cost),
            'marginPct' => $revenue > 0 ? (int) round(100 * ($revenue - $cost) / $revenue) : 0,
            'negative' => $revenue < $cost,
            'costKnown' => $costKnown,
        ];
    }

    public static function attention()
    {
        $rows = DB::table(Schema::SERVICES)->whereIn('state', ['failed', 'unknown', 'creating'])->orderBy('updated_at', 'desc')->limit(20)->get();
        $list = [];
        foreach ($rows as $row) {
            $list[] = ['service' => $row->service_id, 'state' => $row->state, 'error' => (string) $row->error, 'when' => $row->updated_at, 'url' => self::serviceUrl($row->service_id)];
        }
        return $list;
    }

    // ------------------------------------------------------------------ plans

    public static function plans()
    {
        $plans = (new Catalog(self::api()))->plans(true);
        $currency = strtoupper((string) config('settings.default_currency', 'USD'));
        $byPackage = [];
        foreach (self::evorxaProducts() as $product) {
            $pkg = (int) optional($product->settings->firstWhere('key', 'plan'))->value;
            $monthly = null;
            foreach ($product->plans as $plan) {
                if ($plan->type === 'recurring' && $plan->billing_unit === 'month' && (int) $plan->billing_period === 1) {
                    $price = $plan->prices->firstWhere('currency_code', $currency);
                    $monthly = $price ? (float) $price->price : null;
                }
            }
            $byPackage[$pkg][] = ['id' => $product->id, 'name' => $product->name, 'hidden' => (bool) $product->hidden, 'monthly' => $monthly, 'url' => self::url('filament.admin.resources.products.edit', ['record' => $product->id])];
        }
        $rows = [];
        foreach ($plans as $id => $plan) {
            $cost = isset($plan['pricing']['monthly']['total']) ? (float) $plan['pricing']['monthly']['total'] : null;
            $products = isset($byPackage[$id]) ? $byPackage[$id] : [];
            foreach ($products as &$product) {
                $product['margin'] = $cost && $product['monthly'] ? round(100 * ($product['monthly'] - $cost) / $product['monthly']) . '%' : '';
                $product['monthlyText'] = $product['monthly'] !== null ? number_format($product['monthly'], 2) : '';
            }
            unset($product);
            $rows[] = [
                'id' => $id,
                'category' => preg_replace('/\s+VMs?$/i', '', $plan['category_name']),
                'name' => Importer::productName($plan),
                'specs' => Catalog::specLine($plan),
                'stock' => (int) (isset($plan['stock']) ? $plan['stock'] : 0),
                'monthly' => $cost !== null ? number_format($cost, 2) : '-',
                'semi' => isset($plan['pricing']['6months']['total']) ? number_format((float) $plan['pricing']['6months']['total'], 2) : '-',
                'yearly' => isset($plan['pricing']['yearly']['total']) ? number_format((float) $plan['pricing']['yearly']['total'], 2) : '-',
                'products' => $products,
            ];
        }
        $categories = [];
        foreach (Category::orderBy('sort')->orderBy('name')->get(['id', 'name']) as $c) {
            $categories[$c->id] = $c->name;
        }
        return ['plans' => $rows, 'categories' => $categories, 'defaultCategory' => (int) Category::where('slug', 'cloud-vps')->value('id')];
    }

    // ------------------------------------------------------------------ servers

    public static function servers()
    {
        $api = self::api();
        $projects = (new Catalog($api))->ownedProjects();
        $projectIds = array_values(array_filter(array_unique(array_merge(
            [Settings::projectId()],
            DB::table(Schema::SERVICES)->whereNotNull('project_id')->distinct()->pluck('project_id')->all()
        ))));
        $mapped = [];
        foreach (DB::table(Schema::SERVICES)->whereNotNull('instance_id')->get() as $row) {
            $mapped[$row->instance_id] = $row;
        }
        $services = Service::whereIn('id', array_map(function ($r) {
            return $r->service_id;
        }, $mapped))->get()->keyBy('id');
        $list = [];
        $seen = [];
        foreach ($projectIds as $projectId) {
            foreach ((array) $api->get('instances', ['project_id' => (int) $projectId]) as $inst) {
                if (!is_array($inst) || !isset($inst['id']) || (int) (isset($inst['project_id']) ? $inst['project_id'] : 0) !== (int) $projectId) {
                    continue;
                }
                $seen[$inst['id']] = true;
                $row = isset($mapped[$inst['id']]) ? $mapped[$inst['id']] : null;
                $service = $row && isset($services[$row->service_id]) ? $services[$row->service_id] : null;
                $flag = '';
                if (!$row || $row->state === 'terminated') {
                    $flag = 'Not linked to an active service (still billed on Evorxa)';
                } elseif (!$service || $service->status === 'cancelled') {
                    $flag = 'Service is ' . ($service ? 'cancelled' : 'deleted') . ' in Paymenter but the server still exists';
                } elseif ($service->status === 'active' && !empty($inst['suspended_at'])) {
                    $flag = 'Suspended upstream while the service is active';
                }
                $list[] = [
                    'id' => $inst['id'],
                    'name' => isset($inst['name']) ? $inst['name'] : '',
                    'hostname' => isset($inst['hostname']) ? $inst['hostname'] : '',
                    'ip' => isset($inst['main_ip']) ? $inst['main_ip'] : '',
                    'plan' => isset($inst['plan']) ? $inst['plan'] : '',
                    'status' => isset($inst['status']) ? $inst['status'] : '',
                    'billedTill' => Util::date(isset($inst['billed_till']) ? $inst['billed_till'] : null),
                    'deletion' => Util::date(isset($inst['scheduled_deletion_at']) ? $inst['scheduled_deletion_at'] : null),
                    'project' => isset($projects[$projectId]) ? $projects[$projectId] : '#' . $projectId,
                    'service' => $row && $row->state !== 'terminated' ? (int) $row->service_id : null,
                    'serviceStatus' => $service ? $service->status : '',
                    'flag' => $flag,
                ];
            }
        }
        $missing = [];
        foreach ($mapped as $instanceId => $row) {
            if (!isset($seen[$instanceId]) && in_array($row->state, ['active', 'provisioning'], true)) {
                $missing[] = ['service' => (int) $row->service_id, 'instance' => $instanceId, 'project' => (int) $row->project_id];
            }
        }
        return ['servers' => $list, 'missing' => $missing, 'projectNames' => array_map(function ($id) use ($projects) {
            return isset($projects[$id]) ? $projects[$id] . ' (#' . $id . ')' : '#' . $id;
        }, $projectIds)];
    }

    /** One service: mapping row, cached upstream snapshot and recent activity. */
    public static function service($serviceId)
    {
        $service = Repo::service($serviceId);
        if (!$service) {
            return null;
        }
        $row = Repo::find($serviceId);
        $snap = Repo::snapshot($row);
        $plan = null;
        if ($row && $row->package_id) {
            try {
                $plan = (new Catalog())->plan($row->package_id);
            } catch (\Throwable $e) {
            }
        }
        return [
            'id' => (int) $service->id,
            'url' => self::serviceUrl($service->id),
            'product' => $service->product ? $service->product->name : '',
            'client' => $service->user ? $service->user->name . ' (#' . $service->user->id . ')' : '',
            'status' => $service->status,
            'expires' => $service->expires_at ? $service->expires_at->format('Y-m-d') : '',
            'isEvorxa' => $service->product && $service->product->server && $service->product->server->extension === Client::MODULE,
            'row' => $row ? [
                'state' => $row->state,
                'instance' => $row->instance_id,
                'name' => $row->name,
                'project' => $row->project_id,
                'plan' => $plan ? Catalog::planLabel($plan, true) . ($row->upstream_cycle ? ' (upstream billed ' . $row->upstream_cycle . ')' : '') : ($row->package_id ? '#' . $row->package_id : ''),
                'image' => $row->os_label,
                'app' => $row->app_slug,
                'hostname' => $row->hostname,
                'ip' => $row->ip,
                'username' => $row->username,
                'hasPassword' => !empty($row->password),
                'upstream' => isset($snap['status']) ? $snap['status'] : '',
                'billing' => isset($snap['billing_status']) ? $snap['billing_status'] : '',
                'billedTill' => Util::date(isset($snap['billed_till']) ? $snap['billed_till'] : null, true),
                'deletion' => Util::date(isset($snap['scheduled_deletion_at']) ? $snap['scheduled_deletion_at'] : null, true),
                'location' => isset($snap['location']) ? $snap['location'] : '',
                'lastSync' => $row->last_sync_at,
                'readyEmail' => $row->ready_notified_at,
                'error' => (string) $row->error,
            ] : null,
            'logs' => self::logRows(Repo::logs($serviceId, 12)),
        ];
    }

    /** Admin buttons for one service. */
    public static function serviceAction($serviceId, $action, $argument = null)
    {
        $service = Repo::service($serviceId);
        if (!$service) {
            throw new \RuntimeException('Service #' . (int) $serviceId . ' was not found.');
        }
        $server = $service->product ? $service->product->server : null;
        if (!$server || $server->extension !== Client::MODULE) {
            throw new \RuntimeException('Service #' . (int) $serviceId . ' is not an Evorxa product.');
        }
        $provisioner = new Provisioner(
            $service,
            ExtensionHelper::settingsToArray($service->product->settings),
            ExtensionHelper::getServiceProperties($service),
            Client::forServer($server)
        );
        switch ($action) {
            case 'boot':
            case 'restart':
            case 'shutdown':
            case 'poweroff':
                $result = $provisioner->power($action);
                break;
            case 'reset_password':
                $result = $provisioner->resetPassword();
                break;
            case 'sync':
                $result = $provisioner->sync();
                break;
            case 'reactivate':
                $result = $provisioner->reactivate();
                break;
            case 'cancel_deletion':
                $result = $provisioner->cancelDeletion();
                break;
            case 'link':
                $result = $provisioner->linkExisting($argument);
                break;
            default:
                throw new \RuntimeException('Unknown action.');
        }
        if ($result !== 'success') {
            throw new \RuntimeException($result);
        }
        return true;
    }

    // ------------------------------------------------------------------ log

    public static function log($service = 0, $page = 1)
    {
        $page = max(1, (int) $page);
        $query = DB::table(Schema::LOG)->orderBy('id', 'desc');
        if ($service) {
            $query->where('service_id', (int) $service);
        }
        $total = (clone $query)->count();
        $rows = $query->offset(($page - 1) * 50)->limit(50)->get();
        return ['logs' => self::logRows($rows), 'pageNo' => $page, 'hasNext' => $total > $page * 50, 'total' => $total];
    }

    public static function logRows($rows)
    {
        $list = [];
        foreach ($rows as $log) {
            $list[] = [
                'when' => $log->created_at,
                'service' => $log->service_id,
                'actor' => $log->actor,
                'action' => $log->action,
                'ok' => (bool) $log->ok,
                'message' => (string) $log->message,
                'amount' => $log->amount_cents === null ? '' : Util::formatCents($log->amount_cents),
            ];
        }
        return $list;
    }

    // ------------------------------------------------------------------ settings

    public static function settings()
    {
        $projects = [];
        $error = '';
        try {
            $projects = (new Catalog(self::api()))->ownedProjects();
        } catch (\Throwable $e) {
            $error = $e instanceof ApiException ? $e->friendly() : $e->getMessage();
        }
        $template = null;
        try {
            Mailer::install();
            $template = NotificationTemplate::where('key', Mailer::TEMPLATE)->value('id');
        } catch (\Throwable $e) {
        }
        return [
            'projects' => $projects,
            'projectsError' => $error,
            'labels' => self::FEATURE_LABELS,
            'emailTemplateUrl' => $template ? self::url('filament.admin.resources.notification-templates.edit', ['record' => $template]) : '',
        ];
    }

    public static function saveSettings(array $in)
    {
        $project = (int) (isset($in['project_id']) ? $in['project_id'] : 0);
        $owned = (new Catalog(self::api()))->ownedProjects();
        if ($project && !isset($owned[$project])) {
            throw new \RuntimeException('That Evorxa project is not one you own.');
        }
        $suffix = strtolower(trim((string) (isset($in['hostname_suffix']) ? $in['hostname_suffix'] : ''), " .\t"));
        if ($suffix !== '' && !Util::validFqdn($suffix)) {
            throw new \RuntimeException('The hostname suffix must be a domain such as example.com.');
        }
        Settings::set('project_id', $project ?: '');
        Settings::set('hostname_suffix', $suffix);
        Settings::set('low_balance', max(0, (float) (isset($in['low_balance']) ? $in['low_balance'] : 0)));
        Settings::set('provision_timeout', max(10, min(240, (int) (isset($in['provision_timeout']) ? $in['provision_timeout'] : 30))));
        Settings::set('picker', empty($in['picker']) ? '0' : '1');
        Settings::set('debug', empty($in['debug']) ? '0' : '1');
        foreach (Settings::FEATURES as $feature) {
            Settings::set('feature_' . $feature, empty($in['feature_' . $feature]) ? '0' : '1');
        }
        Cache::delete('dash:account');
    }

    // ------------------------------------------------------------------ helpers

    public static function serviceUrl($serviceId)
    {
        return self::url('filament.admin.services.resources.services.edit', ['record' => (int) $serviceId]);
    }

    public static function url($name, array $params = [])
    {
        try {
            return route($name, $params);
        } catch (\Throwable $e) {
            return '';
        }
    }

    public static function ago($ts)
    {
        $d = time() - $ts;
        if ($d < 120) {
            return $d . ' seconds ago';
        }
        if ($d < 7200) {
            return floor($d / 60) . ' minutes ago';
        }
        return floor($d / 3600) . ' hours ago';
    }
}

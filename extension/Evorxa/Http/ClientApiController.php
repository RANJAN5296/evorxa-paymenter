<?php

namespace Paymenter\Extensions\Servers\Evorxa\Http;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Paymenter\Extensions\Servers\Evorxa\Api\ApiException;
use Paymenter\Extensions\Servers\Evorxa\Api\Client;
use Paymenter\Extensions\Servers\Evorxa\Services\Panel;
use Paymenter\Extensions\Servers\Evorxa\Services\Provisioner;
use Paymenter\Extensions\Servers\Evorxa\Services\Watcher;
use Paymenter\Extensions\Servers\Evorxa\Support\Cache;
use Paymenter\Extensions\Servers\Evorxa\Support\Catalog;
use Paymenter\Extensions\Servers\Evorxa\Support\Lang;
use Paymenter\Extensions\Servers\Evorxa\Support\Repo;
use Paymenter\Extensions\Servers\Evorxa\Support\Schema;
use Paymenter\Extensions\Servers\Evorxa\Support\Util;

/**
 * JSON endpoint for the client panel: POST /evorxa/services/{service}/api
 *
 * The route runs in the "web" + "auth" middleware (session and CSRF token checked by Laravel),
 * and the service policy must allow the user to view the service. On top of that the action
 * must be whitelisted and enabled, and every upstream id (instance, Shield IP, firewall rule)
 * is resolved server-side from the service - never taken from the request.
 */
class ClientApiController extends Controller
{
    const ACTIONS = [
        'status' => null,
        'power' => 'power',
        'password_reveal' => null,
        'password_reset' => 'password',
        'app_reveal' => null,
        'reinstall_options' => 'reinstall',
        'reinstall' => 'reinstall',
        'metrics' => 'graphs',
        'ddos' => 'ddos',
        'ddos_save' => 'ddos',
        'firewall' => 'firewall',
        'firewall_add' => 'firewall',
        'firewall_delete' => 'firewall',
        'firewall_policy' => 'firewall',
        'firewall_order' => 'firewall',
        'rdns_save' => 'rdns',
    ];

    const METRIC_PERIODS = ['1h', '6h', '24h'];
    const DDOS_PERIODS = ['live', '1h', '1d', '1w'];

    /** @var Service */
    private $service;
    private $sid;
    private $post = [];
    private $row;
    private $snap;
    private $api;

    public function __invoke(Request $request, $service)
    {
        $service = Service::with(['product.server', 'plan', 'user'])->find((int) $service);
        if (!$service) {
            return $this->json(['ok' => false, 'error' => Lang::t('err_unavailable'), 'code' => 'err_unavailable'], 404);
        }
        Gate::authorize('view', $service);
        $server = $service->product ? $service->product->server : null;
        if (!$server || $server->extension !== Client::MODULE) {
            return $this->json(['ok' => false, 'error' => Lang::t('err_unavailable'), 'code' => 'err_unavailable'], 404);
        }
        $this->service = $service;
        $this->sid = (int) $service->id;
        $this->post = $request->post();
        Repo::$actor = (int) $service->user_id === (int) Auth::id() ? 'client' : 'admin:' . (int) Auth::id();
        try {
            $out = $this->handle();
        } catch (\Throwable $e) {
            report($e);
            $out = ['ok' => false, 'error' => Lang::t('err_generic')];
        }
        return $this->json($out);
    }

    private function json(array $out, $status = 200)
    {
        return response()->json($out, $status, [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function handle()
    {
        $action = isset($this->post['do']) ? (string) $this->post['do'] : '';
        if (!array_key_exists($action, self::ACTIONS)) {
            return $this->fail('err_generic');
        }

        Schema::ensure();
        $this->row = Repo::find($this->sid);
        $this->snap = Repo::snapshot($this->row);
        $serviceStatus = (string) $this->service->status;

        if ($action === 'status') {
            return $this->status($serviceStatus);
        }
        if ($serviceStatus !== 'active' || !$this->row || !$this->row->instance_id || $this->row->state !== 'active') {
            return $this->fail($this->row && $this->row->state === 'provisioning' ? 'err_building' : 'err_unavailable');
        }
        $feature = self::ACTIONS[$action];
        if ($feature !== null) {
            $features = Panel::features($this->snap, $this->row);
            if (empty($features[$feature])) {
                return $this->fail('err_unavailable');
            }
        }

        try {
            $this->api = Watcher::clientFor($this->service);
            $method = 'act' . str_replace(' ', '', ucwords(str_replace('_', ' ', $action)));
            return $this->$method();
        } catch (ApiException $e) {
            Repo::log($this->sid, 'client_' . $action, false, $e->friendly(), null, $this->row->instance_id);
            if ($e->status() === 429) {
                return $this->fail('err_busy');
            }
            return $this->fail('err_generic');
        }
    }

    // ------------------------------------------------------------------ status

    private function status($serviceStatus)
    {
        $state = Panel::state($this->row, $serviceStatus);
        if ($state === 'provisioning' && $this->row && $this->row->instance_id && Cache::acquire('finalize:' . $this->sid, 8)) {
            try {
                Watcher::finalize(Watcher::clientFor($this->service), $this->row);
            } catch (\Throwable $e) {
            }
            $this->row = Repo::find($this->sid);
            $this->snap = Repo::snapshot($this->row);
            $state = Panel::state($this->row, $serviceStatus);
        } elseif ($state === 'active') {
            $age = time() - (int) (isset($this->snap['synced_at']) ? $this->snap['synced_at'] : 0);
            if ($age > 8 && Cache::acquire('status:' . $this->sid, 8)) {
                try {
                    $inst = Provisioner::unwrap(Watcher::clientFor($this->service)->get('instances/' . rawurlencode($this->row->instance_id), [], ['low' => true]));
                    if (Provisioner::fenceOk($this->row, $inst)) {
                        $this->snap = Provisioner::snapshotOf($inst);
                        Repo::update($this->sid, ['snapshot' => json_encode($this->snap), 'last_sync_at' => Repo::now()]);
                    }
                } catch (ApiException $e) {
                    // Keep showing the cached snapshot.
                }
            }
        }
        $t = Lang::load();
        $ip = isset($this->snap['main_ip']) ? $this->snap['main_ip'] : '';
        return $this->ok([
            'state' => $state,
            'phase' => Panel::phase($this->snap),
            'status' => Panel::status($this->snap, $state, $t),
            'ip' => Util::clean($ip, 64),
            'hostname' => Util::clean(isset($this->snap['hostname']) ? $this->snap['hostname'] : '', 190),
        ]);
    }

    // ------------------------------------------------------------------ power & access

    private function actPower()
    {
        $op = isset($this->post['op']) ? (string) $this->post['op'] : '';
        if (!in_array($op, ['boot', 'shutdown', 'restart', 'poweroff'], true)) {
            return $this->fail('err_generic');
        }
        if ($wait = $this->cooldown('power', 15)) {
            return $wait;
        }
        $this->api->post($this->instancePath('power/' . $op));
        Repo::update($this->sid, ['last_sync_at' => null]);
        $this->snap['synced_at'] = 0;
        Repo::update($this->sid, ['snapshot' => json_encode($this->snap)]);
        Repo::log($this->sid, 'power_' . $op, true, 'Power ' . $op, null, $this->row->instance_id, 'client');
        return $this->ok(['message' => Lang::t('js_power_sent')]);
    }

    private function actPasswordReveal()
    {
        $password = Repo::password($this->row);
        if ($password === '') {
            return $this->fail('err_no_password');
        }
        return $this->ok(['password' => $password]);
    }

    private function actPasswordReset()
    {
        if ($wait = $this->cooldown('password', 300)) {
            return $wait;
        }
        $provisioner = new Provisioner(
            $this->service,
            ExtensionHelper::settingsToArray($this->service->product->settings),
            ExtensionHelper::getServiceProperties($this->service),
            $this->api
        );
        $password = $provisioner->doResetPassword($this->row);
        if ($password === null) {
            return $this->fail('err_generic');
        }
        return $this->ok(['password' => $password, 'message' => Lang::t('js_password_reset_done')]);
    }

    private function actAppReveal()
    {
        $res = $this->api->get($this->instancePath('app'), [], ['low' => true]);
        $app = isset($res['app']) && is_array($res['app']) ? $res['app'] : null;
        if (!$app) {
            return $this->ok(['app' => null]);
        }
        $apps = (new Catalog())->apps();
        $slug = isset($app['slug']) ? $app['slug'] : '';
        return $this->ok(['app' => [
            'name' => Util::clean(isset($app['name']) ? $app['name'] : $slug, 60),
            'status' => Util::clean(isset($app['status']) ? $app['status'] : '', 30),
            'url' => self::safeUrl(isset($app['login_url']) ? $app['login_url'] : ''),
            'username' => Util::clean(isset($app['username']) ? $app['username'] : '', 120),
            'password' => isset($app['password']) && is_string($app['password']) ? $app['password'] : '',
            'note' => isset($apps[$slug]['setup_note']) ? $apps[$slug]['setup_note'] : '',
        ]]);
    }

    // ------------------------------------------------------------------ reinstall

    private function actReinstallOptions()
    {
        $catalog = new Catalog($this->api);
        $groups = [];
        foreach ($catalog->reinstallOptions($this->row->instance_id, (int) $this->row->package_id) as $os) {
            $key = $os['family'] ?: 'Other';
            if (!isset($groups[$key])) {
                $family = Panel::osFamily($key);
                $groups[$key] = ['family' => $key, 'icon' => $family, 'logo' => Panel::logo('os', $family), 'items' => []];
            }
            $groups[$key]['items'][] = ['id' => $os['id'], 'label' => $os['label'], 'eol' => $os['eol'], 'type' => $os['type']];
        }
        $apps = [];
        $features = Panel::features($this->snap, $this->row);
        $plan = $catalog->plan((int) $this->row->package_id);
        if ($features['apps'] && $plan) {
            foreach ($catalog->appsFor($plan) as $app) {
                $apps[] = [
                    'slug' => $app['slug'],
                    'name' => $app['name'],
                    'tagline' => $app['tagline'],
                    'category' => $app['category'],
                    'logo' => Panel::logo('apps', $app['slug']),
                ];
            }
        }
        return $this->ok(['groups' => array_values($groups), 'apps' => $apps]);
    }

    private function actReinstall()
    {
        $osId = isset($this->post['os_id']) ? (int) $this->post['os_id'] : 0;
        $appSlug = isset($this->post['app']) ? strtolower(trim((string) $this->post['app'])) : '';
        $catalog = new Catalog($this->api);
        $body = [];
        $label = '';
        if ($appSlug !== '') {
            $plan = $catalog->plan((int) $this->row->package_id);
            $apps = $plan ? $catalog->appsFor($plan) : [];
            $features = Panel::features($this->snap, $this->row);
            if (!$features['apps'] || !isset($apps[$appSlug])) {
                return $this->fail('err_generic');
            }
            $body['app_slug'] = $appSlug;
            $label = $apps[$appSlug]['name'];
        } else {
            $match = null;
            foreach ($catalog->reinstallOptions($this->row->instance_id, (int) $this->row->package_id) as $os) {
                if ($os['id'] === $osId) {
                    $match = $os;
                    break;
                }
            }
            if (!$match) {
                return $this->fail('err_generic');
            }
            $body['os_template_id'] = $match['id'];
            $body['os_name'] = $match['label'];
            $label = $match['label'];
        }
        if ($wait = $this->cooldown('reinstall', 300)) {
            return $wait;
        }
        $this->api->post($this->instancePath('reinstall'), $body, ['timeout' => 60]);
        Repo::update($this->sid, [
            'state' => 'provisioning',
            'app_slug' => $appSlug !== '' ? $appSlug : null,
            'os_label' => Util::clean($label, 190),
            'creating_at' => Repo::now(),
            'alerted_at' => null,
        ]);
        Repo::log($this->sid, 'reinstall', true, 'Reinstall: ' . $label, null, $this->row->instance_id, 'client');
        return $this->ok(['message' => Lang::t('js_reinstall_started')]);
    }

    // ------------------------------------------------------------------ graphs

    private function actMetrics()
    {
        $period = isset($this->post['period']) && in_array($this->post['period'], self::METRIC_PERIODS, true) ? $this->post['period'] : '1h';
        $data = Cache::remember('metrics:' . $this->sid . ':' . $period, 60, function () use ($period) {
            return self::shapeMetrics($this->api->get($this->instancePath('metrics'), ['period' => $period], ['low' => true]));
        });
        return $this->ok($data);
    }

    public static function shapeMetrics($raw)
    {
        $pct = function ($used, $total) {
            return $total > 0 ? round(100 * $used / $total, 1) : null;
        };
        $cur = isset($raw['current']) && is_array($raw['current']) ? $raw['current'] : [];
        $memUsed = (float) (isset($cur['memory_used_kb']) ? $cur['memory_used_kb'] : 0) * 1024;
        $memTotal = (float) (isset($cur['memory_total_kb']) ? $cur['memory_total_kb'] : 0) * 1024;
        $diskUsed = (float) (isset($cur['disk_used_bytes']) ? $cur['disk_used_bytes'] : 0);
        $diskTotal = (float) (isset($cur['disk_total_bytes']) ? $cur['disk_total_bytes'] : 0);

        $points = [];
        $prev = null;
        $history = isset($raw['history']) && is_array($raw['history']) ? $raw['history'] : [];
        usort($history, function ($a, $b) {
            return strcmp((string) (isset($a['created_at']) ? $a['created_at'] : ''), (string) (isset($b['created_at']) ? $b['created_at'] : ''));
        });
        foreach ($history as $h) {
            $ts = Util::ts(isset($h['created_at']) ? $h['created_at'] : null);
            if (!$ts) {
                continue;
            }
            $rx = (float) (isset($h['network_rx_bytes']) ? $h['network_rx_bytes'] : 0);
            $tx = (float) (isset($h['network_tx_bytes']) ? $h['network_tx_bytes'] : 0);
            $rxRate = null;
            $txRate = null;
            if ($prev && $ts > $prev[0]) {
                $dt = $ts - $prev[0];
                // Counters reset on reboot: a negative delta contributes nothing rather than a spike.
                $rxRate = $rx >= $prev[1] ? round(8 * ($rx - $prev[1]) / $dt) : 0;
                $txRate = $tx >= $prev[2] ? round(8 * ($tx - $prev[2]) / $dt) : 0;
            }
            $prev = [$ts, $rx, $tx];
            $points[] = [
                $ts * 1000,
                round((float) (isset($h['cpu_percent']) ? $h['cpu_percent'] : 0), 1),
                $pct((float) (isset($h['memory_used_kb']) ? $h['memory_used_kb'] : 0), (float) (isset($h['memory_total_kb']) ? $h['memory_total_kb'] : 0)),
                $pct((float) (isset($h['disk_used_bytes']) ? $h['disk_used_bytes'] : 0), (float) (isset($h['disk_total_bytes']) ? $h['disk_total_bytes'] : 0)),
                $rxRate,
                $txRate,
            ];
        }
        return [
            'current' => [
                'cpu' => round((float) (isset($cur['cpu_percent']) ? $cur['cpu_percent'] : 0), 1),
                'memPct' => $pct($memUsed, $memTotal),
                'memText' => Util::formatBytes($memUsed) . ' / ' . Util::formatBytes($memTotal),
                'diskPct' => $pct($diskUsed, $diskTotal),
                'diskText' => Util::formatBytes($diskUsed) . ' / ' . Util::formatBytes($diskTotal),
                'rxText' => Util::formatBytes(isset($cur['network_rx_bytes']) ? $cur['network_rx_bytes'] : 0),
                'txText' => Util::formatBytes(isset($cur['network_tx_bytes']) ? $cur['network_tx_bytes'] : 0),
            ],
            'points' => $points,
        ];
    }

    // ------------------------------------------------------------------ DDoS

    private function actDdos()
    {
        $period = isset($this->post['period']) && in_array($this->post['period'], self::DDOS_PERIODS, true) ? $this->post['period'] : 'live';
        $overview = Cache::remember('ddos:' . $this->sid . ':' . $period, $period === 'live' ? 12 : 120, function () use ($period) {
            $raw = $this->api->get($this->instancePath('ddos/overview'), ['period' => $period], ['low' => true]);
            $points = [];
            foreach ((array) (isset($raw['points']) ? $raw['points'] : []) as $p) {
                $ts = Util::ts(isset($p['time']) ? $p['time'] : null);
                if ($ts) {
                    $points[] = [$ts * 1000, (int) (isset($p['drop_bps']) ? $p['drop_bps'] : 0), (int) (isset($p['pass_bps']) ? $p['pass_bps'] : 0)];
                }
            }
            return ['supported' => !isset($raw['supported']) || !empty($raw['supported']), 'points' => $points];
        });
        $incidents = Cache::remember('ddos-incidents:' . $this->sid, 300, function () {
            $raw = $this->api->get($this->instancePath('ddos/incidents'), ['page' => 1], ['low' => true]);
            $items = [];
            foreach ((array) (isset($raw['items']) ? $raw['items'] : []) as $i) {
                $items[] = [
                    'start' => Util::date(isset($i['started_at']) ? $i['started_at'] : null, true),
                    'duration' => self::duration((int) (isset($i['duration_seconds']) ? $i['duration_seconds'] : 0)),
                    'vectors' => Util::clean(implode(', ', array_map('strval', (array) (isset($i['vectors']) ? $i['vectors'] : []))), 120),
                    'peak' => Util::clean(isset($i['peak']) ? $i['peak'] : '', 30),
                ];
            }
            return ['items' => $items, 'total' => (int) (isset($raw['total_items']) ? $raw['total_items'] : count($items))];
        });
        $settings = Cache::remember('ddos-settings:' . $this->sid, 300, function () {
            return $this->api->get($this->instancePath('ddos/settings'), [], ['low' => true]);
        });
        return $this->ok([
            'supported' => $overview['supported'],
            'points' => $overview['points'],
            'incidents' => $incidents['items'],
            'incidentTotal' => $incidents['total'],
            'settings' => [
                'email' => !empty($settings['email_notifications']),
            ],
        ]);
    }

    /** Only the email toggle is exposed; webhook and affiliate settings are never sent. */
    private function actDdosSave()
    {
        $body = ['email_notifications' => !empty($this->post['email']) && $this->post['email'] !== '0'];
        if ($wait = $this->cooldown('ddos_save', 5)) {
            return $wait;
        }
        $saved = $this->api->put($this->instancePath('ddos/settings'), $body);
        Cache::set('ddos-settings:' . $this->sid, is_array($saved) && isset($saved['email_notifications']) ? $saved : $body, 300);
        Repo::log($this->sid, 'ddos_settings', true, 'Attack alert settings updated', null, $this->row->instance_id, 'client');
        return $this->ok(['message' => Lang::t('js_saved')]);
    }

    // ------------------------------------------------------------------ firewall

    private function actFirewall()
    {
        list($ipId, $address) = $this->shieldIp();
        if (!$ipId) {
            return $this->ok(['available' => false]);
        }
        // Reads are cached briefly; every change below writes the fresh state back.
        $state = Cache::remember('fw:' . $this->sid, 30, function () use ($ipId) {
            return $this->firewallState($ipId);
        });
        return $this->ok(['available' => true, 'address' => $address] + $state);
    }

    /** Fresh firewall state after a change, also stored for the next read. */
    private function firewallChanged($ipId)
    {
        $state = $this->firewallState($ipId);
        Cache::set('fw:' . $this->sid, $state, 30);
        return ['available' => true] + $state;
    }

    private function actFirewallAdd()
    {
        list($ipId) = $this->shieldIp();
        if (!$ipId) {
            return $this->fail('err_unavailable');
        }
        $direction = isset($this->post['direction']) ? (string) $this->post['direction'] : 'in';
        $action = isset($this->post['rule_action']) ? (string) $this->post['rule_action'] : '';
        $protocol = isset($this->post['protocol']) ? (string) $this->post['protocol'] : '';
        $source = trim(isset($this->post['source']) ? (string) $this->post['source'] : '');
        $port = trim(isset($this->post['port']) ? (string) $this->post['port'] : '');
        if (!in_array($direction, ['in', 'out'], true) || !in_array($action, ['allow', 'block'], true) || !in_array($protocol, ['tcp', 'udp', 'any'], true)) {
            return $this->fail('err_rule');
        }
        if ($source !== '' && !Util::validCidr($source)) {
            return $this->fail('err_cidr');
        }
        $ports = self::parsePorts($port);
        if ($ports === false) {
            return $this->fail('err_port');
        }
        if ($ports && $protocol === 'any') {
            return $this->fail('err_port_protocol');
        }
        $state = $this->firewallState($ipId);
        if (count($state['rules']) >= 50) {
            return $this->fail('err_rule_limit');
        }
        if ($wait = $this->cooldown('firewall', 2)) {
            return $wait;
        }
        $last = 0;
        foreach ($state['rules'] as $rule) {
            $last = max($last, $rule['priority']);
        }
        $this->api->post('shield/ips/' . (int) $ipId . '/rules', [
            'priority' => $last + 10, // new rules go to the bottom of the list
            'direction' => $direction,
            'action' => $action === 'block' ? 'deny' : 'allow',
            'protocol' => $protocol,
            'source_type' => 'ip',
            'source_value' => $source !== '' ? $source : '0.0.0.0/0',
            'port_start' => $ports ? $ports[0] : null,
            'port_end' => $ports ? $ports[1] : null,
        ]);
        Repo::log($this->sid, 'firewall_add', true, ucfirst($action) . ' ' . $direction . ' ' . $protocol . ($ports ? ' port ' . $port : '') . ' ' . ($source ?: 'anywhere'), null, $this->row->instance_id, 'client');
        return $this->ok(['message' => Lang::t('js_rule_added')] + $this->firewallChanged($ipId));
    }

    /** "" => [] (all ports), "22" => [22, 22], "8000-8100" => [8000, 8100], invalid => false. */
    public static function parsePorts($value)
    {
        $value = str_replace(' ', '', (string) $value);
        if ($value === '') {
            return [];
        }
        if (!preg_match('/^([0-9]{1,5})(?:[-:]([0-9]{1,5}))?$/', $value, $m)) {
            return false;
        }
        $start = (int) $m[1];
        $end = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : $start;
        if ($start < 1 || $end > 65535 || $end < $start) {
            return false;
        }
        return [$start, $end];
    }

    private function actFirewallDelete()
    {
        list($ipId) = $this->shieldIp();
        $ruleId = isset($this->post['rule_id']) ? (int) $this->post['rule_id'] : 0;
        if (!$ipId || $ruleId <= 0) {
            return $this->fail('err_generic');
        }
        $owned = false;
        foreach ($this->firewallState($ipId)['rules'] as $rule) {
            if ($rule['id'] === $ruleId) {
                $owned = true;
                break;
            }
        }
        if (!$owned) {
            return $this->fail('err_generic');
        }
        if ($wait = $this->cooldown('firewall', 2)) {
            return $wait;
        }
        $this->api->delete('shield/ips/' . (int) $ipId . '/rules/' . $ruleId);
        Repo::log($this->sid, 'firewall_delete', true, 'Rule #' . $ruleId . ' deleted', null, $this->row->instance_id, 'client');
        return $this->ok(['message' => Lang::t('js_rule_deleted')] + $this->firewallChanged($ipId));
    }

    private function actFirewallPolicy()
    {
        list($ipId) = $this->shieldIp();
        $in = isset($this->post['in']) ? (string) $this->post['in'] : '';
        $out = isset($this->post['out']) ? (string) $this->post['out'] : '';
        $enabled = !empty($this->post['enabled']) && $this->post['enabled'] !== '0';
        if (!$ipId || !in_array($in, ['allow', 'block'], true) || !in_array($out, ['allow', 'block'], true)) {
            return $this->fail('err_generic');
        }
        if ($wait = $this->cooldown('firewall', 2)) {
            return $wait;
        }
        $this->api->put('shield/ips/' . (int) $ipId . '/policy', [
            'inbound_default' => $in === 'block' ? 'drop' : 'accept',
            'outbound_default' => $out === 'block' ? 'drop' : 'accept',
            'enabled' => $enabled,
        ]);
        Repo::log($this->sid, 'firewall_policy', true, 'Firewall ' . ($enabled ? 'on' : 'off') . ', default in ' . $in . ', out ' . $out, null, $this->row->instance_id, 'client');
        return $this->ok(['message' => Lang::t('js_saved')] + $this->firewallChanged($ipId));
    }

    /**
     * Reorder rules (drag and drop). "order" lists every current rule id top to bottom;
     * priorities become 10, 20, 30... and only rules whose priority changes are updated.
     */
    private function actFirewallOrder()
    {
        list($ipId) = $this->shieldIp();
        if (!$ipId) {
            return $this->fail('err_unavailable');
        }
        $ids = array_values(array_filter(array_map('intval', explode(',', isset($this->post['order']) ? (string) $this->post['order'] : ''))));
        $state = $this->firewallState($ipId);
        $current = [];
        foreach ($state['rules'] as $rule) {
            $current[$rule['id']] = $rule['priority'];
        }
        $sortedIds = $ids;
        sort($sortedIds);
        $sortedCurrent = array_keys($current);
        sort($sortedCurrent);
        if (!$ids || $sortedIds !== $sortedCurrent) {
            // Stale page or foreign ids: refuse and hand back the real list.
            return ['ok' => false, 'error' => Lang::t('err_rules_changed'), 'code' => 'err_rules_changed', 'data' => ['available' => true] + $state];
        }
        if ($wait = $this->cooldown('firewall', 2)) {
            return $wait;
        }
        foreach ($ids as $i => $id) {
            $priority = ($i + 1) * 10;
            if ($current[$id] !== $priority) {
                $this->api->put('shield/ips/' . (int) $ipId . '/rules/' . $id, ['priority' => $priority]);
            }
        }
        Repo::log($this->sid, 'firewall_order', true, 'Rule order: ' . implode(', ', $ids), null, $this->row->instance_id, 'client');
        return $this->ok(['message' => Lang::t('js_order_saved')] + $this->firewallChanged($ipId));
    }

    /** Change the reverse DNS of one of this server's IP addresses. */
    private function actRdnsSave()
    {
        $address = trim(isset($this->post['address']) ? (string) $this->post['address'] : '');
        $rdns = strtolower(trim(isset($this->post['rdns']) ? (string) $this->post['rdns'] : '', " .\t\r\n"));
        if (!Util::validFqdn($rdns)) {
            return $this->fail('err_rdns');
        }
        // Only addresses Evorxa lists for *this* instance are accepted.
        $ipId = 0;
        foreach ((array) $this->api->get('shield/ips', [], ['low' => true]) as $ip) {
            if (is_array($ip) && isset($ip['instance_id'], $ip['address'], $ip['id'])
                && (string) $ip['instance_id'] === (string) $this->row->instance_id && (string) $ip['address'] === $address) {
                $ipId = (int) $ip['id'];
                break;
            }
        }
        if (!$ipId) {
            return $this->fail('err_unavailable');
        }
        if ($wait = $this->cooldown('rdns', 10)) {
            return $wait;
        }
        try {
            $this->api->put('shield/ips/' . $ipId . '/rdns', ['rdns' => $rdns]);
        } catch (ApiException $e) {
            if ($e->status() === 422) {
                return $this->fail('err_rdns');
            }
            throw $e;
        }
        if (!empty($this->snap['ip_addresses'])) {
            foreach ($this->snap['ip_addresses'] as &$ip) {
                if ($ip['address'] === $address) {
                    $ip['rdns'] = $rdns;
                }
            }
            unset($ip);
            Repo::update($this->sid, ['snapshot' => json_encode($this->snap)]);
        }
        Repo::log($this->sid, 'rdns', true, 'rDNS of ' . $address . ' set to ' . $rdns, null, $this->row->instance_id, 'client');
        return $this->ok(['address' => $address, 'rdns' => $rdns, 'message' => Lang::t('js_rdns_saved')]);
    }

    /** Shield IP record of this server's primary address, resolved server-side. */
    private function shieldIp()
    {
        $key = 'shieldip:' . $this->sid . ':' . $this->row->instance_id;
        $found = Cache::remember($key, 86400, function () {
            $candidates = [];
            foreach ((array) $this->api->get('shield/ips', [], ['low' => true]) as $ip) {
                if (is_array($ip) && isset($ip['instance_id']) && (string) $ip['instance_id'] === (string) $this->row->instance_id) {
                    $candidates[] = $ip;
                }
            }
            usort($candidates, function ($a, $b) {
                return (int) !empty($b['is_primary']) - (int) !empty($a['is_primary']);
            });
            return $candidates ? [(int) $candidates[0]['id'], (string) $candidates[0]['address']] : [0, ''];
        });
        return is_array($found) ? $found : [0, ''];
    }

    private function firewallState($ipId)
    {
        $raw = $this->api->get('shield/ips/' . (int) $ipId, [], ['low' => true]);
        if (isset($raw['instance_id']) && (string) $raw['instance_id'] !== (string) $this->row->instance_id) {
            throw new ApiException('Shield IP does not belong to this server', 403);
        }
        $policy = isset($raw['policy']) && is_array($raw['policy']) ? $raw['policy'] : [];
        $norm = function ($v) {
            $v = strtolower((string) $v);
            return in_array($v, ['block', 'drop', 'deny', 'reject'], true) ? 'block' : 'allow';
        };
        $rules = [];
        foreach ((array) (isset($raw['rules']) ? $raw['rules'] : []) as $r) {
            if (!is_array($r) || !isset($r['id'])) {
                continue;
            }
            $start = isset($r['port_start']) && $r['port_start'] !== null && $r['port_start'] !== '' ? (int) $r['port_start'] : null;
            $end = isset($r['port_end']) && $r['port_end'] !== null && $r['port_end'] !== '' ? (int) $r['port_end'] : $start;
            $type = strtolower(isset($r['source_type']) ? (string) $r['source_type'] : 'ip');
            $value = Util::clean(isset($r['source_value']) ? $r['source_value'] : '', 64);
            $rules[] = [
                'id' => (int) $r['id'],
                'priority' => isset($r['priority']) ? (int) $r['priority'] : 100,
                'direction' => isset($r['direction']) && $r['direction'] === 'out' ? 'out' : 'in',
                'action' => $norm(isset($r['action']) ? $r['action'] : 'allow'),
                'protocol' => Util::clean(strtolower(isset($r['protocol']) ? $r['protocol'] : 'any'), 8),
                'port' => $start === null ? '' : ($end && $end !== $start ? $start . '-' . $end : (string) $start),
                'source' => $type === 'ip' ? $value : strtoupper($type) . ' ' . $value,
                'anywhere' => $type === 'ip' && in_array($value, ['', '0.0.0.0/0', '::/0'], true),
            ];
        }
        // Evorxa evaluates lower priority first; show them in that order.
        usort($rules, function ($a, $b) {
            return $a['priority'] === $b['priority'] ? $a['id'] - $b['id'] : $a['priority'] - $b['priority'];
        });
        return [
            'enabled' => !empty($policy['enabled']),
            'policy' => [
                'in' => $norm(isset($policy['inbound_default']) ? $policy['inbound_default'] : 'accept'),
                'out' => $norm(isset($policy['outbound_default']) ? $policy['outbound_default'] : 'accept'),
            ],
            'rules' => $rules,
        ];
    }

    // ------------------------------------------------------------------ helpers

    private function instancePath($suffix)
    {
        return 'instances/' . rawurlencode($this->row->instance_id) . '/' . $suffix;
    }

    /** Per-service cooldown shared across sessions; returns a failure response while cooling down. */
    private function cooldown($group, $seconds)
    {
        $key = 'cd:' . $this->sid . ':' . $group;
        if (Cache::acquire($key, $seconds)) {
            return null;
        }
        return $this->fail('err_cooldown', ['seconds' => max(1, Cache::remaining($key))]);
    }

    private function ok(array $data)
    {
        return ['ok' => true, 'data' => $data];
    }

    private function fail($key, array $replace = [])
    {
        return ['ok' => false, 'error' => Lang::t($key, $replace), 'code' => $key];
    }

    private static function safeUrl($url)
    {
        $url = trim((string) $url);
        return preg_match('#^https?://[^\s"<>]+$#i', $url) ? $url : '';
    }

    private static function duration($seconds)
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
        }
        return floor($seconds / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'm';
    }
}

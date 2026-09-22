<?php

namespace Paymenter\Extensions\Servers\Evorxa\Services;

use App\Models\Service;
use Paymenter\Extensions\Servers\Evorxa\Api\ApiException;
use Paymenter\Extensions\Servers\Evorxa\Api\Client;
use Paymenter\Extensions\Servers\Evorxa\Support\Cache;
use Paymenter\Extensions\Servers\Evorxa\Support\Catalog;
use Paymenter\Extensions\Servers\Evorxa\Support\Repo;
use Paymenter\Extensions\Servers\Evorxa\Support\Schema;
use Paymenter\Extensions\Servers\Evorxa\Support\Settings;
use Paymenter\Extensions\Servers\Evorxa\Support\Util;

/**
 * Paymenter lifecycle actions. Every public method returns 'success' or an admin-facing error
 * (Evorxa.php turns errors into exceptions, which Paymenter shows to the admin / fails the job with).
 *
 * Money safety: the service row is claimed ("creating") before POST /instances, the POST is never
 * retried, an unclear answer becomes "unknown" and the watcher adopts the server by its unique name,
 * and nothing outside the service's Evorxa project is ever touched.
 */
class Provisioner
{
    /** Upstream statuses that mean "still being built". */
    const BUILDING = ['provisioning', 'building', 'installing', 'reinstalling', 'pending', 'creating', 'queued'];

    private $service;
    private $sid;
    private $settings;
    private $properties;
    private $api;
    private $catalog;

    /**
     * @param array $settings   product settings (plan, default_os, upstream_term)
     * @param array $properties service properties (os, app, hostname from checkout)
     */
    public function __construct(Service $service, array $settings, array $properties, Client $api)
    {
        $this->service = $service;
        $this->sid = (int) $service->id;
        $this->settings = $settings;
        $this->properties = $properties;
        $this->api = $api;
        $this->catalog = new Catalog($this->api);
        Schema::ensure();
    }

    public function api()
    {
        return $this->api;
    }

    // ------------------------------------------------------------------ create

    public function create()
    {
        $cycle = Util::upstreamCycleForPlan($this->service->plan, $this->setting('upstream_term', 'match'));
        if (!$cycle) {
            return 'Evorxa servers need a recurring monthly or yearly billing plan; this service is "' . Util::planCycleLabel($this->service->plan) . '".';
        }

        $row = Repo::find($this->sid);
        if ($row && $row->instance_id && !in_array($row->state, ['terminated', 'failed'], true)) {
            return $this->resumeExisting($row);
        }
        if ($row && in_array($row->state, ['creating', 'unknown'], true)) {
            if (Watcher::adopt($this->api, $row, false)) {
                return 'success';
            }
            return 'A previous create for this service is not confirmed yet. It is checked every minute '
                . '(or use "Sync now" in Admin > Evorxa > Servers); retry only if it is marked failed.';
        }

        $projectId = Settings::projectId();
        if (!$projectId) {
            return 'Choose the Evorxa project for new servers in Admin > Evorxa > Settings.';
        }
        $packageId = (int) $this->setting('plan');
        $plan = $this->catalog->plan($packageId, true);
        if (!$plan) {
            return 'Evorxa plan #' . $packageId . ' does not exist. Pick a plan in the product\'s server settings.';
        }
        if (isset($plan['stock']) && (int) $plan['stock'] <= 0) {
            return 'The Evorxa plan "' . $plan['name'] . '" is out of stock. Retry when stock is back.';
        }
        $price = Catalog::cycleCents($plan, $cycle);
        if ($price === null) {
            return 'The Evorxa plan "' . $plan['name'] . '" has no ' . $cycle . ' billing cycle.';
        }

        $balance = $this->api->get('wallet/balance');
        $available = isset($balance['balance_cents']) ? (int) $balance['balance_cents'] : 0;
        if ($available < $price) {
            $msg = 'Insufficient Evorxa wallet balance: this server costs ' . Util::formatCents($price)
                . ' but the wallet has ' . Util::formatCents($available) . '. Top up and retry.';
            Mailer::alertAdmin('wallet-short', 'Evorxa wallet too low to provision', $msg . ' (service #' . $this->sid . ')', 3600);
            return $msg;
        }

        $body = [
            'project_id' => $projectId,
            'package_id' => $packageId,
            'category' => isset($plan['category']) ? $plan['category'] : 'standard',
            'billing_cycle' => $cycle,
        ];

        $app = $this->chosenApp($plan);
        $osLabel = '';
        if ($app) {
            $body['app_slug'] = $app['slug'];
            $osLabel = $app['name'];
        } else {
            $os = $this->chosenOs($packageId);
            if (!$os) {
                return 'The selected operating system is not available for this plan. Check the product\'s Default OS.';
            }
            $body['os_template_id'] = $os['id'];
            $body['os_name'] = $os['label'];
            $osLabel = $os['label'];
        }

        $hostname = Util::hostname($this->property('hostname'), $this->sid, Settings::get('hostname_suffix'));
        if (!Util::validFqdn($hostname)) {
            return 'Set a hostname suffix (for example example.com) in Admin > Evorxa > Settings.';
        }
        $name = Settings::installPrefix() . '-' . $this->sid;
        $body['name'] = $name;
        $body['hostname'] = $hostname;

        if (!Repo::claimCreating($this->sid, [
            'name' => $name,
            'project_id' => $projectId,
            'package_id' => $packageId,
            'upstream_cycle' => $cycle,
            'app_slug' => $app ? $app['slug'] : null,
            'os_label' => Util::clean($osLabel, 190),
            'ssh_key_id' => null,
            'hostname' => $hostname,
        ])) {
            return 'Another create for this service is already running.';
        }

        $sshKeyId = $this->registerSshKey($name);
        if ($sshKeyId) {
            $body['ssh_key_ids'] = [$sshKeyId];
            Repo::update($this->sid, ['ssh_key_id' => $sshKeyId]);
        }

        try {
            $inst = self::unwrap($this->api->post('instances', $body, ['timeout' => 90]));
        } catch (ApiException $e) {
            if ($e->isDefinite()) {
                Repo::update($this->sid, ['state' => 'failed', 'error' => $e->friendly()]);
                Repo::log($this->sid, 'create', false, $e->friendly());
                $this->deleteSshKey($sshKeyId);
                if ($e->status() === 402) {
                    Mailer::alertAdmin('wallet-short', 'Evorxa wallet too low to provision', $e->friendly() . ' (service #' . $this->sid . ')', 3600);
                }
                return $e->friendly();
            }
            Repo::update($this->sid, ['state' => 'unknown', 'error' => $e->friendly()]);
            Repo::log($this->sid, 'create', false, 'No confirmation from Evorxa: ' . $e->friendly() . ' Checking automatically.');
            return 'Evorxa did not confirm the order (' . $e->friendly() . '). The extension checks automatically and adopts '
                . 'the server if it was created. Do not retry for 15 minutes.';
        }

        $instanceId = isset($inst['id']) ? (string) $inst['id'] : '';
        if ($instanceId === '') {
            Repo::update($this->sid, ['state' => 'unknown', 'error' => 'Create response had no instance id']);
            Repo::log($this->sid, 'create', false, 'Create response had no instance id; checking automatically.');
            return 'Evorxa accepted the order but returned no server id. The extension checks automatically.';
        }

        Repo::update($this->sid, [
            'state' => 'provisioning',
            'instance_id' => $instanceId,
            'snapshot' => json_encode(self::snapshotOf($inst)),
            'last_sync_at' => Repo::now(),
            'error' => null,
            'username' => isset($inst['username']) && $inst['username'] ? Util::clean($inst['username'], 60) : 'root',
        ]);
        $charged = Keeper::charged($inst);
        Repo::log($this->sid, 'create', true, 'Server ordered: ' . $plan['name'] . ' (' . $cycle . '), ' . $osLabel . ', ' . $hostname, $charged !== null ? $charged : $price, $instanceId);
        self::labelService($this->service, $hostname);
        return 'success';
    }

    /** Create was called on a service that already has a server. */
    private function resumeExisting($row)
    {
        try {
            $inst = $this->instance($row);
        } catch (ApiException $e) {
            if ($e->status() === 404) {
                Repo::update($this->sid, ['state' => 'failed', 'error' => 'Linked server no longer exists upstream']);
                Repo::log($this->sid, 'create', false, 'Linked server ' . $row->instance_id . ' is gone upstream; marked failed so Create can build a new one.');
                return 'The linked Evorxa server no longer exists. Run Create again to build a new one.';
            }
            return $e->friendly();
        }
        if (!empty($inst['scheduled_deletion_at'])) {
            $this->api->post('instances/' . rawurlencode($row->instance_id) . '/cancel-deletion');
            $this->powerQuiet($row->instance_id, 'boot');
            Repo::log($this->sid, 'cancel_deletion', true, 'Create on a server scheduled for deletion: deletion cancelled', null, $row->instance_id);
            return 'success';
        }
        return 'This service already has Evorxa server ' . $row->instance_id . '. Nothing was created.';
    }

    // ------------------------------------------------------------------ lifecycle

    public function suspend()
    {
        $row = Repo::find($this->sid);
        if (!$row || !$row->instance_id || $row->state === 'terminated') {
            return 'success';
        }
        $this->instance($row); // fence check
        $this->powerQuiet($row->instance_id, 'poweroff');
        Repo::log($this->sid, 'suspend', true, 'Powered off for suspension', null, $row->instance_id);
        return 'success';
    }

    public function unsuspend()
    {
        $row = Repo::find($this->sid);
        if (!$row || !$row->instance_id || $row->state === 'terminated') {
            return 'success';
        }
        $inst = $this->instance($row);
        $id = rawurlencode($row->instance_id);
        if (Keeper::upstreamSuspended($inst)) {
            $res = $this->api->post('instances/' . $id . '/reactivate');
            Repo::log($this->sid, 'reactivate', true, 'Reactivated on unsuspend', Keeper::charged($res), $row->instance_id);
        }
        if (!empty($inst['scheduled_deletion_at']) && !Keeper::pendingEndOfPeriodCancel($this->sid)) {
            $this->api->post('instances/' . $id . '/cancel-deletion');
            Repo::log($this->sid, 'cancel_deletion', true, 'Scheduled deletion cancelled on unsuspend', null, $row->instance_id);
        }
        $this->powerQuiet($row->instance_id, 'boot');
        Repo::log($this->sid, 'unsuspend', true, 'Booted after unsuspension', null, $row->instance_id);
        return 'success';
    }

    public function terminate()
    {
        $row = Repo::find($this->sid);
        if (!$row) {
            return 'success';
        }
        if (in_array($row->state, ['creating', 'unknown'], true)) {
            return 'A create for this service is still unconfirmed. Wait for the sync (or use "Sync now") before terminating.';
        }
        if (!$row->instance_id || $row->state === 'terminated') {
            Repo::update($this->sid, ['state' => 'terminated']);
            return 'success';
        }
        try {
            $inst = $this->instance($row);
            $path = 'instances/' . rawurlencode($row->instance_id);
            if (!empty($inst['scheduled_deletion_at'])) {
                // Evorxa refuses an immediate delete (409) while an end-of-cycle deletion is pending.
                try {
                    $this->api->post($path . '/cancel-deletion');
                } catch (ApiException $e) {
                    // Handled below if the delete is still refused.
                }
            }
            try {
                $res = $this->api->delete($path, ['mode' => 'immediate']);
            } catch (ApiException $e) {
                if ($e->status() === 409 && stripos($e->upstreamMessage(), 'scheduled') !== false) {
                    $when = Util::date(isset($inst['scheduled_deletion_at']) ? $inst['scheduled_deletion_at'] : null, true);
                    Repo::log($this->sid, 'terminate', true, 'Already scheduled for deletion' . ($when ? ' on ' . $when : '') . '; Evorxa removes it then', null, $row->instance_id);
                    $this->deleteSshKey($row->ssh_key_id);
                    Repo::update($this->sid, ['state' => 'terminated', 'ssh_key_id' => null]);
                    return 'success';
                }
                throw $e;
            }
            $refund = null;
            foreach (['refund_cents', 'refunded_cents', 'refund'] as $key) {
                if (isset($res[$key]) && is_numeric($res[$key])) {
                    $refund = -abs((int) $res[$key]);
                    break;
                }
            }
            Repo::log($this->sid, 'terminate', true, 'Server deleted' . ($refund ? ', prorated refund ' . Util::formatCents(-$refund) : ''), $refund, $row->instance_id);
        } catch (ApiException $e) {
            if ($e->status() !== 404) {
                Repo::log($this->sid, 'terminate', false, $e->friendly(), null, $row->instance_id);
                return $e->friendly();
            }
            Repo::log($this->sid, 'terminate', true, 'Server was already gone upstream', null, $row->instance_id);
        }
        $this->deleteSshKey($row->ssh_key_id);
        Repo::update($this->sid, ['state' => 'terminated', 'ssh_key_id' => null]);
        return 'success';
    }

    /** Called after a paid upgrade: the service already points at the new product. */
    public function changePackage()
    {
        $row = Repo::find($this->sid);
        if (!$row || !$row->instance_id) {
            return 'No Evorxa server is linked to this service.';
        }
        $target = (int) $this->setting('plan');
        if ($target === (int) $row->package_id) {
            return 'success';
        }
        $this->instance($row);
        try {
            $res = $this->api->post('instances/' . rawurlencode($row->instance_id) . '/upgrade', [
                'package_id' => $target,
                'reboot' => true,
            ]);
        } catch (ApiException $e) {
            Repo::log($this->sid, 'change_package', false, $e->friendly(), null, $row->instance_id);
            Mailer::alertAdmin('upgrade-' . $this->sid, 'Plan change failed for service #' . $this->sid, $e->friendly(), 3600);
            return $e->friendly();
        }
        $plan = $this->catalog->plan($target);
        Repo::update($this->sid, ['package_id' => $target]);
        Repo::log($this->sid, 'change_package', true, 'Plan changed to ' . ($plan ? $plan['name'] : '#' . $target) . ' (server rebooted)', Keeper::charged($res), $row->instance_id);
        return 'success';
    }

    // ------------------------------------------------------------------ admin actions

    public function power($action)
    {
        $row = $this->requireLinked();
        $this->instance($row);
        $this->api->post('instances/' . rawurlencode($row->instance_id) . '/power/' . $action);
        Cache::delete('inst:' . $row->instance_id);
        Repo::log($this->sid, 'power_' . $action, true, 'Power ' . $action, null, $row->instance_id);
        return 'success';
    }

    public function resetPassword()
    {
        $row = $this->requireLinked();
        $this->instance($row);
        $password = $this->doResetPassword($row);
        return $password === null ? 'Evorxa did not return a new password.' : 'success';
    }

    /** Returns the new password (also stored encrypted on the mapping row) or null. */
    public function doResetPassword($row)
    {
        $res = $this->api->post('instances/' . rawurlencode($row->instance_id) . '/reset-password');
        $password = self::findPassword($res);
        if ($password !== null) {
            Repo::update($this->sid, ['password' => encrypt($password)]);
        }
        Repo::log($this->sid, 'reset_password', $password !== null, $password !== null ? 'Root password reset' : 'Reset answered without a password', null, $row->instance_id);
        return $password;
    }

    public function sync()
    {
        $row = Repo::find($this->sid);
        if (!$row) {
            return 'No Evorxa server is linked to this service.';
        }
        if (in_array($row->state, ['creating', 'unknown'], true)) {
            return Watcher::adopt($this->api, $row, true) ? 'success' : 'Still no server with name ' . $row->name . ' in the Evorxa project.';
        }
        if (!$row->instance_id) {
            return 'No Evorxa server is linked to this service.';
        }
        if ($row->state === 'provisioning') {
            Watcher::finalize($this->api, $row);
            return 'success';
        }
        $inst = $this->instance($row);
        Repo::update($this->sid, ['snapshot' => json_encode(self::snapshotOf($inst)), 'last_sync_at' => Repo::now()]);
        Keeper::forService($this->sid, $this->api);
        return 'success';
    }

    public function reactivate()
    {
        $row = $this->requireLinked();
        $this->instance($row);
        $res = $this->api->post('instances/' . rawurlencode($row->instance_id) . '/reactivate');
        Repo::log($this->sid, 'reactivate', true, 'Reactivated by admin', Keeper::charged($res), $row->instance_id);
        return 'success';
    }

    public function cancelDeletion()
    {
        $row = $this->requireLinked();
        $this->instance($row);
        $this->api->post('instances/' . rawurlencode($row->instance_id) . '/cancel-deletion');
        Repo::log($this->sid, 'cancel_deletion', true, 'Scheduled deletion cancelled by admin', null, $row->instance_id);
        return 'success';
    }

    /**
     * Attach an Evorxa server that already exists (nothing is created or charged).
     * The server must be in one of your projects and not linked to another service.
     */
    public function linkExisting($instanceId)
    {
        $instanceId = trim((string) $instanceId);
        if ($instanceId === 'unlink') {
            $row = Repo::find($this->sid);
            if ($row) {
                Repo::update($this->sid, ['state' => 'terminated', 'instance_id' => null]);
                Repo::log($this->sid, 'unlink', true, 'Server ' . $row->instance_id . ' unlinked by admin (not deleted)', null, $row->instance_id);
            }
            return 'success';
        }
        if (!preg_match('/^[A-Za-z0-9-]{6,64}$/', $instanceId)) {
            return 'Enter an Evorxa server id.';
        }
        $other = Repo::findByInstance($instanceId);
        if ($other && (int) $other->service_id !== $this->sid) {
            return 'That server is already linked to service #' . (int) $other->service_id . '.';
        }
        try {
            $inst = self::unwrap($this->api->get('instances/' . rawurlencode($instanceId)));
        } catch (ApiException $e) {
            return $e->friendly();
        }
        $owned = array_keys($this->catalog->ownedProjects());
        if (!isset($inst['project_id']) || !in_array((int) $inst['project_id'], $owned, true)) {
            return 'That server is not in one of your Evorxa projects.';
        }
        Repo::link($this->sid, [
            'instance_id' => (string) $inst['id'],
            'project_id' => (int) $inst['project_id'],
            'package_id' => isset($inst['package_id']) ? (int) $inst['package_id'] : (int) $this->setting('plan'),
            'upstream_cycle' => isset($inst['billing_cycle']) ? (string) $inst['billing_cycle'] : null,
            'name' => isset($inst['name']) ? Util::clean($inst['name'], 100) : null,
            'state' => Provisioner::isBuilding($inst) ? 'provisioning' : 'active',
            'os_label' => Util::clean(isset($inst['os']) ? $inst['os'] : '', 190),
            'snapshot' => json_encode(self::snapshotOf($inst)),
            'last_sync_at' => Repo::now(),
            'ready_notified_at' => Repo::now(),
            'error' => null,
        ]);
        Watcher::writeCredentials($this->sid, $inst);
        Repo::log($this->sid, 'link', true, 'Linked existing server ' . $inst['id'], null, (string) $inst['id']);
        return 'success';
    }

    // ------------------------------------------------------------------ helpers

    /** GET the linked instance and refuse to touch it when it is outside the service's project. */
    public function instance($row)
    {
        $inst = self::unwrap($this->api->get('instances/' . rawurlencode($row->instance_id)));
        if (!self::fenceOk($row, $inst)) {
            throw new ApiException('Refusing to act: server ' . $row->instance_id . ' is not in Evorxa project #' . (int) $row->project_id . '.', 403);
        }
        return $inst;
    }

    public static function fenceOk($row, array $inst)
    {
        $expected = $row->project_id ? (int) $row->project_id : Settings::projectId();
        return isset($inst['project_id']) && (int) $inst['project_id'] === $expected
            && isset($inst['id']) && (string) $inst['id'] === (string) $row->instance_id;
    }

    private function requireLinked()
    {
        $row = Repo::find($this->sid);
        if (!$row || !$row->instance_id || in_array($row->state, ['terminated', 'creating', 'unknown'], true)) {
            throw new ApiException('No active Evorxa server is linked to this service.');
        }
        return $row;
    }

    private function powerQuiet($instanceId, $action)
    {
        try {
            $this->api->post('instances/' . rawurlencode($instanceId) . '/power/' . $action);
        } catch (ApiException $e) {
            // Already in the requested state is fine; anything else is recorded.
            if ($e->status() !== 422 && $e->status() !== 409) {
                Repo::log($this->sid, 'power_' . $action, false, $e->friendly(), null, $instanceId);
            }
        }
    }

    private function chosenApp(array $plan)
    {
        $slug = strtolower(trim((string) $this->property('app')));
        if ($slug === '' || $slug === 'none') {
            return null;
        }
        $apps = $this->catalog->appsFor($plan);
        return isset($apps[$slug]) ? $apps[$slug] : null;
    }

    private function chosenOs($packageId)
    {
        foreach ([$this->property('os'), $this->setting('default_os')] as $slug) {
            $slug = strtolower(trim((string) $slug));
            if ($slug !== '' && ($os = $this->catalog->osBySlug($packageId, $slug))) {
                return $os;
            }
        }
        // Last resort: newest Ubuntu LTS, else the first non-EOL Linux image.
        $best = null;
        foreach ($this->catalog->osTemplates($packageId) as $os) {
            if ($os['eol'] || $os['type'] !== 'linux') {
                continue;
            }
            if (!$best) {
                $best = $os;
            }
            if (strpos($os['slug'], 'ubuntu-') === 0 && strpos($os['label'], 'LTS') !== false) {
                $best = $os;
            }
        }
        return $best;
    }

    private function registerSshKey($name)
    {
        $key = trim(preg_replace('/\s+/', ' ', (string) $this->property('sshkey')));
        if ($key === '') {
            return null;
        }
        if (!Util::validSshKey($key)) {
            Repo::log($this->sid, 'ssh_key', false, 'Ignored the SSH key from the order: not a valid OpenSSH public key');
            return null;
        }
        try {
            $res = self::unwrap($this->api->post('ssh-keys', ['name' => $name, 'public_key' => $key]));
            return isset($res['id']) ? (int) $res['id'] : null;
        } catch (ApiException $e) {
            Repo::log($this->sid, 'ssh_key', false, 'Could not register the SSH key: ' . $e->friendly());
            return null;
        }
    }

    private function deleteSshKey($id)
    {
        self::deleteSshKeyWith($this->api, $id);
    }

    public static function deleteSshKeyWith(Client $api, $id)
    {
        if (!$id) {
            return;
        }
        try {
            $api->delete('ssh-keys/' . (int) $id);
        } catch (ApiException $e) {
        }
    }

    /** Show the hostname as the service label in the client area (only when the client hasn't set one). */
    public static function labelService($service, $hostname)
    {
        try {
            if ($service && !$service->label && $hostname) {
                $service->label = Util::clean($hostname, 190);
                $service->saveQuietly();
            }
        } catch (\Throwable $e) {
        }
    }

    private function setting($key, $default = '')
    {
        return isset($this->settings[$key]) && $this->settings[$key] !== '' && $this->settings[$key] !== null ? $this->settings[$key] : $default;
    }

    private function property($key)
    {
        return isset($this->properties[$key]) ? (string) $this->properties[$key] : '';
    }

    /** Some endpoints wrap the record ({"instance": {...}} / {"data": {...}}). */
    public static function unwrap($res)
    {
        if (!is_array($res)) {
            return [];
        }
        foreach (['instance', 'data', 'key'] as $wrap) {
            if (isset($res[$wrap]) && is_array($res[$wrap]) && isset($res[$wrap]['id'])) {
                return $res[$wrap];
            }
        }
        return $res;
    }

    public static function findPassword($res)
    {
        if (!is_array($res)) {
            return null;
        }
        foreach (['password', 'root_password', 'new_password'] as $key) {
            if (!empty($res[$key]) && is_string($res[$key])) {
                return $res[$key];
            }
        }
        foreach ($res as $value) {
            if (is_array($value) && ($found = self::findPassword($value)) !== null) {
                return $found;
            }
        }
        return null;
    }

    /** Whitelisted, password-free copy of an instance for the local cache. */
    public static function snapshotOf(array $inst)
    {
        $keep = ['id', 'name', 'hostname', 'project_id', 'os', 'os_template_id', 'plan', 'vcpu', 'ram', 'storage',
            'uplink', 'main_ip', 'status', 'location', 'location_code', 'location_country_code', 'username',
            'billed_till', 'billing_cycle', 'billing_status', 'scheduled_deletion_at', 'suspended_at', 'deleted_at',
            'allow_upgrade', 'allow_reinstall', 'allow_reboot', 'allow_stop', 'allow_reset_password',
            'lock_monitoring', 'lock_ddos', 'lock_firewall', 'vf_state', 'created_at'];
        $snap = [];
        foreach ($keep as $key) {
            if (array_key_exists($key, $inst)) {
                $snap[$key] = $inst[$key];
            }
        }
        $snap['ip_addresses'] = [];
        foreach ((array) (isset($inst['ip_addresses']) ? $inst['ip_addresses'] : []) as $ip) {
            if (!empty($ip['address'])) {
                $snap['ip_addresses'][] = [
                    'id' => isset($ip['id']) ? (int) $ip['id'] : null,
                    'address' => (string) $ip['address'],
                    'type' => isset($ip['type']) ? (string) $ip['type'] : '',
                    'rdns' => isset($ip['rdns']) ? (string) $ip['rdns'] : '',
                    'is_primary' => !empty($ip['is_primary']),
                ];
            }
        }
        if (!empty($inst['current_app']) && is_array($inst['current_app'])) {
            $snap['current_app'] = [
                'slug' => isset($inst['current_app']['slug']) ? $inst['current_app']['slug'] : '',
                'name' => isset($inst['current_app']['name']) ? $inst['current_app']['name'] : '',
            ];
        }
        if (isset($inst['vf_remote_state']['state'])) {
            $snap['power_state'] = $inst['vf_remote_state']['state'];
        }
        $snap['synced_at'] = time();
        return $snap;
    }

    public static function isBuilding(array $inst)
    {
        $status = strtolower(isset($inst['status']) ? (string) $inst['status'] : '');
        $vf = strtolower(isset($inst['vf_state']) ? (string) $inst['vf_state'] : '');
        return in_array($status, self::BUILDING, true) || in_array($vf, ['building', 'provisioning', 'pending', 'queued'], true);
    }
}

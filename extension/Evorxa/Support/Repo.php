<?php

namespace Paymenter\Extensions\Servers\Evorxa\Support;

use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service <-> instance mapping and the activity log.
 *
 * States: new, creating (write-ahead before POST), unknown (POST result unclear),
 * provisioning, active, failed, terminated.
 */
class Repo
{
    /** Set by the client API controller so log rows say who acted. */
    public static $actor = null;

    public static function find($serviceId)
    {
        Schema::ensure();
        return DB::table(Schema::SERVICES)->where('service_id', (int) $serviceId)->first();
    }

    public static function findByInstance($instanceId)
    {
        Schema::ensure();
        return DB::table(Schema::SERVICES)
            ->where('instance_id', (string) $instanceId)
            ->where('state', '!=', 'terminated')
            ->first();
    }

    public static function update($serviceId, array $data)
    {
        $data['updated_at'] = self::now();
        return DB::table(Schema::SERVICES)->where('service_id', (int) $serviceId)->update($data);
    }

    /**
     * Write-ahead claim before POST /instances. Only one caller can move a service
     * into "creating"; a second job or an admin click racing it gets false.
     */
    public static function claimCreating($serviceId, array $data)
    {
        Schema::ensure();
        $now = self::now();
        $data = array_merge($data, [
            'state' => 'creating',
            'instance_id' => null,
            'error' => null,
            'snapshot' => null,
            'creating_at' => $now,
            'ready_at' => null,
            'ready_notified_at' => null,
            'alerted_at' => null,
            'updated_at' => $now,
        ]);
        try {
            DB::table(Schema::SERVICES)->insert(array_merge($data, [
                'service_id' => (int) $serviceId,
                'created_at' => $now,
            ]));
            return true;
        } catch (\Throwable $e) {
            // Row exists: only restart from a state that has nothing upstream.
            return DB::table(Schema::SERVICES)
                ->where('service_id', (int) $serviceId)
                ->whereIn('state', ['new', 'failed', 'terminated'])
                ->update($data) === 1;
        }
    }

    /** Link an existing instance (admin "link" or adoption). */
    public static function link($serviceId, array $data)
    {
        Schema::ensure();
        $now = self::now();
        $data['updated_at'] = $now;
        if (self::find($serviceId)) {
            return DB::table(Schema::SERVICES)->where('service_id', (int) $serviceId)->update($data);
        }
        return DB::table(Schema::SERVICES)->insert(array_merge($data, [
            'service_id' => (int) $serviceId,
            'created_at' => $now,
        ]));
    }

    public static function snapshot($row)
    {
        if (!$row || empty($row->snapshot)) {
            return [];
        }
        $data = json_decode($row->snapshot, true);
        return is_array($data) ? $data : [];
    }

    public static function log($serviceId, $action, $ok, $message = '', $amountCents = null, $instanceId = null, $actor = null)
    {
        try {
            Schema::ensure();
            DB::table(Schema::LOG)->insert([
                'service_id' => $serviceId ? (int) $serviceId : null,
                'instance_id' => $instanceId,
                'actor' => substr($actor ?: self::actor(), 0, 32),
                'action' => substr((string) $action, 0, 64),
                'ok' => $ok ? 1 : 0,
                'message' => $message === '' ? null : (string) $message,
                'amount_cents' => $amountCents === null ? null : (int) $amountCents,
                'created_at' => self::now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Evorxa: could not write the activity log: ' . $e->getMessage());
        }
    }

    public static function logs($serviceId, $limit = 10)
    {
        Schema::ensure();
        return DB::table(Schema::LOG)
            ->where('service_id', (int) $serviceId)
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get();
    }

    public static function lastLog($serviceId, $action, $okOnly = true)
    {
        $q = DB::table(Schema::LOG)->where('service_id', (int) $serviceId)->where('action', $action);
        if ($okOnly) {
            $q->where('ok', 1);
        }
        return $q->orderBy('id', 'desc')->first();
    }

    public static function actor()
    {
        if (self::$actor) {
            return self::$actor;
        }
        if (app()->runningInConsole()) {
            return 'cron';
        }
        try {
            $user = Auth::user();
            if ($user) {
                return 'admin:' . (int) $user->id;
            }
        } catch (\Throwable $e) {
        }
        return 'system';
    }

    public static function now()
    {
        return now()->format('Y-m-d H:i:s');
    }

    public static function service($serviceId)
    {
        return Service::with(['product', 'plan', 'user'])->find((int) $serviceId);
    }

    /** Root password stored on the mapping row (encrypted with APP_KEY). */
    public static function password($row)
    {
        if (!$row || empty($row->password)) {
            return '';
        }
        try {
            return (string) decrypt($row->password);
        } catch (\Throwable $e) {
            return '';
        }
    }
}

<?php

namespace Paymenter\Extensions\Servers\Evorxa\Support;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache as Store;

/**
 * Small shared cache on Paymenter's cache store (Redis by default).
 *
 * Shared across requests and processes, so it also backs per-service action cooldowns
 * and the scheduler's "every N minutes" gates. acquire() uses the store's atomic add().
 */
class Cache
{
    const PREFIX = 'evx:';

    private static $memo = [];

    private static $locks = [];

    public static function get($key)
    {
        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }
        try {
            $value = Store::get(self::PREFIX . $key);
        } catch (\Throwable $e) {
            return null;
        }
        if ($value !== null) {
            self::$memo[$key] = $value;
        }
        return $value;
    }

    public static function set($key, $value, $ttl)
    {
        self::$memo[$key] = $value;
        try {
            Store::put(self::PREFIX . $key, $value, (int) $ttl);
        } catch (\Throwable $e) {
            // Best effort: a cache miss only costs an extra API call.
        }
    }

    public static function delete($key)
    {
        unset(self::$memo[$key]);
        try {
            Store::forget(self::PREFIX . $key);
        } catch (\Throwable $e) {
        }
    }

    public static function remember($key, $ttl, callable $fn)
    {
        $value = self::get($key);
        if ($value !== null) {
            return $value;
        }
        $value = $fn();
        if ($value !== null) {
            self::set($key, $value, $ttl);
        }
        return $value;
    }

    /**
     * Take a slot for $ttl seconds. Returns false while a previous slot is still running.
     * add() is atomic, so two simultaneous clicks cannot both win.
     */
    public static function acquire($key, $ttl)
    {
        unset(self::$memo[$key]);
        try {
            return Store::add(self::PREFIX . 'slot:' . $key, time() + (int) $ttl, (int) $ttl);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Seconds left on a slot taken with acquire(); 0 when free. */
    public static function remaining($key)
    {
        try {
            $until = (int) Store::get(self::PREFIX . 'slot:' . $key);
        } catch (\Throwable $e) {
            return 0;
        }
        return max(0, $until - time());
    }

    /** Process-wide named lock (the scheduler tick). */
    public static function lock($name, $wait = 0)
    {
        try {
            $lock = Store::lock(self::PREFIX . 'lock:' . $name, 600);
            if ($wait > 0) {
                $lock->block((int) $wait);
            } elseif (!$lock->get()) {
                return false;
            }
            self::$locks[$name] = $lock;
            return true;
        } catch (LockTimeoutException $e) {
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function unlock($name)
    {
        if (isset(self::$locks[$name])) {
            try {
                self::$locks[$name]->release();
            } catch (\Throwable $e) {
            }
            unset(self::$locks[$name]);
        }
    }
}

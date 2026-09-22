<?php

namespace Paymenter\Extensions\Servers\Evorxa\Support;

use App\Helpers\ExtensionHelper;
use Illuminate\Support\Facades\Cache as Store;
use Illuminate\Support\Facades\Schema as DbSchema;

/**
 * Extension tables (database/migrations). Created on first use and never dropped:
 * a lost service-to-instance mapping would leave paid servers running with nobody billing for them.
 *
 * Paymenter only calls installed() for zip uploads, so a copied folder is migrated here instead.
 */
class Schema
{
    const SERVICES = 'evorxa_services';
    const LOG = 'evorxa_log';
    const SETTINGS = 'evorxa_settings';

    /** Bump when a migration is added. */
    const VERSION = 1;

    private static $checked = false;

    public static function ensure()
    {
        if (self::$checked) {
            return;
        }
        self::$checked = true;
        $flag = 'evx:schema:' . self::VERSION;
        try {
            if (Store::get($flag)) {
                return;
            }
        } catch (\Throwable $e) {
        }
        self::migrate();
        try {
            Store::forever($flag, 1);
        } catch (\Throwable $e) {
        }
    }

    public static function migrate()
    {
        if (!DbSchema::hasTable(self::SERVICES) || !DbSchema::hasTable(self::LOG) || !DbSchema::hasTable(self::SETTINGS)
            || !DbSchema::hasColumn(self::SERVICES, 'password')) {
            ExtensionHelper::runMigrations('extensions/Servers/Evorxa/database/migrations');
        }
    }
}

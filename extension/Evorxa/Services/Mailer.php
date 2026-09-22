<?php

namespace Paymenter\Extensions\Servers\Evorxa\Services;

use App\Helpers\NotificationHelper;
use App\Models\NotificationTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Paymenter\Extensions\Servers\Evorxa\Support\Cache;
use Paymenter\Extensions\Servers\Evorxa\Support\Catalog;
use Paymenter\Extensions\Servers\Evorxa\Support\Repo;
use Paymenter\Extensions\Servers\Evorxa\Support\Schema;
use Paymenter\Extensions\Servers\Evorxa\Support\Util;

/**
 * The "server ready" client email and admin alerts.
 *
 * The client email is a normal Paymenter notification template (key evorxa_server_ready),
 * so admins can edit the wording under Admin > Notifications.
 */
class Mailer
{
    const TEMPLATE = 'evorxa_server_ready';

    public static function install()
    {
        try {
            if (NotificationTemplate::where('key', self::TEMPLATE)->exists()) {
                return;
            }
            NotificationTemplate::create([
                'key' => self::TEMPLATE,
                'subject' => 'Your server {{ $hostname }} is ready',
                'body' => self::body(),
                'in_app_title' => 'Server ready',
                'in_app_body' => 'Your server {{ $hostname }} is online and ready to use.',
                'enabled' => true,
                'mail_enabled' => 'force',
                'in_app_enabled' => 'choice_on',
                'edit_preference_message' => 'Notify me when a new cloud server is ready',
                'in_app_url' => '{{ $panel_url }}',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Evorxa: could not create the server ready template: ' . $e->getMessage());
        }
    }

    /**
     * Send the ready email exactly once per service. The claim is an atomic
     * UPDATE, so the scheduler and a client's live panel can both call this safely.
     */
    public static function sendReady($serviceId, array $vars)
    {
        $claimed = DB::table(Schema::SERVICES)
            ->where('service_id', (int) $serviceId)
            ->whereNull('ready_notified_at')
            ->update(['ready_notified_at' => Repo::now()]);
        if (!$claimed) {
            return false;
        }
        self::install();
        $service = Repo::service($serviceId);
        if (!$service || !$service->user) {
            Repo::log($serviceId, 'email_ready', false, 'Email failed: service or client not found');
            return false;
        }
        try {
            NotificationHelper::sendNotification(self::TEMPLATE, $vars + ['service' => $service], $service->user);
            Repo::log($serviceId, 'email_ready', true, 'Server ready email sent');
            return true;
        } catch (\Throwable $e) {
            Repo::log($serviceId, 'email_ready', false, 'Email failed: ' . $e->getMessage());
            return false;
        }
    }

    /** Admin email (System email address) + log entry, de-duplicated per $key for $ttl seconds. */
    public static function alertAdmin($key, $subject, $body, $ttl = 21600)
    {
        if (!Cache::acquire('alert:' . $key, $ttl)) {
            return;
        }
        Log::warning('Evorxa: ' . $subject . ' - ' . $body);
        try {
            NotificationHelper::sendSystemEmailNotification(
                '[Evorxa] ' . $subject,
                $body . "\n\n[Open Evorxa](" . self::adminUrl() . ')'
            );
        } catch (\Throwable $e) {
            Log::warning('Evorxa: could not send the admin alert: ' . $e->getMessage());
        }
    }

    public static function adminUrl()
    {
        return rtrim((string) config('app.url'), '/') . '/admin/evorxa';
    }

    /** Template variables (the password is only ever sent in this one email, like the WHMCS module). */
    public static function readyVars($serviceId, array $inst, $app, $osLabel)
    {
        $row = Repo::find($serviceId);
        $service = Repo::service($serviceId);
        $windows = stripos((string) $osLabel, 'windows') !== false;
        $ip = $row && $row->ip ? $row->ip : (isset($inst['main_ip']) ? (string) $inst['main_ip'] : '');
        $username = $row && $row->username ? $row->username : (isset($inst['username']) ? (string) $inst['username'] : 'root');
        return [
            'hostname' => Util::clean($row && $row->hostname ? $row->hostname : (isset($inst['hostname']) ? $inst['hostname'] : ''), 190),
            'ip' => Util::clean($ip, 64),
            'username' => Util::clean($username, 60),
            'password' => Repo::password($row),
            'os' => Util::clean($osLabel ?: (isset($inst['os']) ? $inst['os'] : ''), 120),
            'is_windows' => $windows,
            'location' => Util::clean(isset($inst['location']) ? $inst['location'] : '', 80),
            'app_name' => $app ? Util::clean(isset($app['name']) ? $app['name'] : '', 80) : '',
            'app_url' => $app ? Util::clean(isset($app['login_url']) ? $app['login_url'] : '', 255) : '',
            'app_username' => $app ? Util::clean(isset($app['username']) ? $app['username'] : '', 120) : '',
            'app_password' => $app ? Util::clean(isset($app['password']) ? $app['password'] : '', 255) : '',
            'app_note' => $app ? Util::clean(isset($app['setup_note']) ? $app['setup_note'] : '', 400) : '',
            'app_missing' => '',
            'panel_url' => $service ? route('services.show', $service) : rtrim((string) config('app.url'), '/') . '/services',
        ];
    }

    /** Markdown + Blade, like Paymenter's built-in templates. */
    private static function body()
    {
        return <<<'MD'
# Your server is ready

Your server **{{ $service->product->name }}** is online and ready to use.

| | |
|---|---|
| Hostname | `{{ $hostname }}` |
| IP address | `{{ $ip }}` |
@if($os)
| Operating system | {{ $os }} |
@endif
@if($location)
| Location | {{ $location }} |
@endif
| Username | `{{ $username }}` |
| Password | `{{ $password }}` |

@if($is_windows)
Connect with Remote Desktop to **{{ $ip }}**.
@else
Connect over SSH: `ssh {{ $username }}@{{ $ip }}`
@endif

@if($app_name)
**{{ $app_name }}** is installed on this server.

@if($app_url)
- Sign-in address: [{{ $app_url }}]({{ $app_url }})
@endif
@if($app_username)
- Username: `{{ $app_username }}`
@endif
@if($app_password)
- Password: `{{ $app_password }}`
@endif

@if($app_note)
{{ $app_note }}
@endif
@endif

@if($app_missing)
**{{ $app_missing }}** could not be installed automatically. Our team has been notified and will help you; the server itself is ready to use.
@endif

Start, stop, reinstall, view usage graphs and manage the firewall from your client area: [{{ $panel_url }}]({{ $panel_url }})

For your security, please change the password after your first sign-in.
MD;
    }
}

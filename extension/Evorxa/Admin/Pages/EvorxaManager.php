<?php

namespace Paymenter\Extensions\Servers\Evorxa\Admin\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Paymenter\Extensions\Servers\Evorxa\Api\ApiException;
use Paymenter\Extensions\Servers\Evorxa\Api\Client;
use Paymenter\Extensions\Servers\Evorxa\Services\Importer;
use Paymenter\Extensions\Servers\Evorxa\Services\Manager;
use Paymenter\Extensions\Servers\Evorxa\Services\Panel;
use Paymenter\Extensions\Servers\Evorxa\Services\Watcher;
use Paymenter\Extensions\Servers\Evorxa\Support\Cache;
use Paymenter\Extensions\Servers\Evorxa\Support\Repo;
use Paymenter\Extensions\Servers\Evorxa\Support\Settings;
use Paymenter\Extensions\Servers\Evorxa\Support\Util;

/**
 * Admin > Evorxa: dashboard, plans & import, servers (with per-service actions), activity log and settings.
 */
class EvorxaManager extends Page
{
    protected string $view = 'evorxa::admin.manager';

    protected static ?string $slug = 'evorxa';

    protected static ?string $title = 'Evorxa Cloud';

    protected static ?string $navigationLabel = 'Evorxa Cloud';

    protected static string|\UnitEnum|null $navigationGroup = 'Extensions';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-cloud-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-cloud-fill';

    const TABS = ['dashboard' => 'Dashboard', 'plans' => 'Plans & Import', 'servers' => 'Servers', 'log' => 'Activity Log', 'settings' => 'Settings'];

    #[Url(except: 'dashboard')]
    public string $tab = 'dashboard';

    #[Url(except: 0, as: 'service')]
    public int $serviceId = 0;

    #[Url(except: 0, as: 'filter')]
    public int $logService = 0;

    #[Url(except: 1, as: 'p')]
    public int $logPage = 1;

    /** Settings form. */
    public array $settings = [];

    /** Import form. */
    public array $import = [
        'packages' => [],
        'category_id' => '',
        'new_category' => '',
        'markup' => 40,
        'rounding' => '99',
        'cycles' => ['monthly', 'semiannually', 'annually'],
        'hidden' => true,
    ];

    public ?array $importReport = null;

    public string $linkInstance = '';

    public string $lookup = '';

    public static function canAccess(): bool
    {
        $user = Auth::user();
        return $user && $user->hasPermission('admin.servers.viewAny');
    }

    public function mount(): void
    {
        if (!isset(self::TABS[$this->tab])) {
            $this->tab = 'dashboard';
        }
        $this->settings = Settings::all();
        foreach (array_keys($this->settings) as $key) {
            if (strpos($key, 'feature_') === 0 || in_array($key, ['picker', 'debug'], true)) {
                $this->settings[$key] = $this->settings[$key] === '1';
            }
        }
    }

    public function getTitle(): string
    {
        return 'Evorxa Cloud';
    }

    // ------------------------------------------------------------------ actions

    public function setTab($tab)
    {
        if (isset(self::TABS[$tab])) {
            $this->tab = $tab;
            if ($tab !== 'servers') {
                $this->serviceId = 0;
            }
        }
    }

    public function saveSettings()
    {
        $this->guard(function () {
            Manager::saveSettings($this->settings);
            $this->notify('Settings saved.');
        });
    }

    public function runImport()
    {
        $this->guard(function () {
            $packages = array_values(array_filter(array_map('intval', (array) $this->import['packages'])));
            if (!$packages) {
                throw new \RuntimeException('Tick at least one plan to import.');
            }
            $server = Client::defaultServer();
            if (!$server) {
                throw new \RuntimeException('Add your Evorxa server first (Admin > Servers).');
            }
            $report = (new Importer($server))->run(array_merge($this->import, ['packages' => $packages]));
            $this->importReport = $report['rows'];
            $created = count(array_filter($report['rows'], function ($r) {
                return $r['status'] === 'created';
            }));
            $this->import['packages'] = [];
            $this->notify($created . ' product(s) created.', $created ? 'success' : 'info');
        });
    }

    public function syncStock()
    {
        $this->guard(function () {
            $changed = Watcher::syncStock();
            $this->notify('Stock synced from Evorxa (' . $changed . ' product(s) updated).');
        });
    }

    public function checkWallet()
    {
        $this->guard(function () {
            Cache::delete('dash:account');
            $cents = Watcher::checkBalance();
            $this->notify('Wallet checked: ' . Util::formatCents($cents) . '. An alert is emailed when it is below your threshold.');
        });
    }

    public function runSync()
    {
        $this->guard(function () {
            Cache::delete('gate:sync');
            Cache::delete('dash:account');
            Watcher::tick();
            $this->notify('Background sync ran.');
        });
    }

    public function openService($id = null)
    {
        $id = (int) ($id ?: preg_replace('/[^0-9]/', '', $this->lookup));
        if ($id > 0) {
            $this->serviceId = $id;
            $this->tab = 'servers';
            $this->linkInstance = '';
        }
    }

    public function closeService()
    {
        $this->serviceId = 0;
    }

    public function serviceAction($action)
    {
        $this->guard(function () use ($action) {
            Manager::serviceAction($this->serviceId, $action, $action === 'link' ? $this->linkInstance : null);
            $this->linkInstance = '';
            $this->notify('Done.');
        }, 'admin.servers.update');
    }

    public function filterLog()
    {
        $this->logPage = 1;
    }

    public function logPageTo($page)
    {
        $this->logPage = max(1, (int) $page);
    }

    private function guard(callable $fn, $permission = 'admin.servers.update')
    {
        if (!Auth::user() || !Auth::user()->hasPermission($permission)) {
            $this->notify('You do not have permission to do this.', 'danger');
            return;
        }
        try {
            $fn();
        } catch (ApiException $e) {
            $this->notify($e->friendly(), 'danger');
        } catch (\Throwable $e) {
            $this->notify($e->getMessage(), 'danger');
        }
    }

    private function notify($message, $type = 'success')
    {
        Notification::make()->title($message)->{$type === 'danger' ? 'danger' : ($type === 'info' ? 'info' : 'success')}()->send();
    }

    // ------------------------------------------------------------------ view data

    protected function getViewData(): array
    {
        Repo::$actor = 'admin:' . (int) Auth::id();
        $data = ['tabs' => self::TABS, 'error' => null, 'assets' => Panel::assetsUrl(), 'v' => Panel::assetVersion(), 'version' => Client::VERSION];
        try {
            switch ($this->tab) {
                case 'plans':
                    $data += Manager::plans();
                    break;
                case 'servers':
                    $data['service'] = $this->serviceId ? Manager::service($this->serviceId) : null;
                    if (!$this->serviceId) {
                        $data += Manager::servers();
                    }
                    break;
                case 'log':
                    $data += Manager::log($this->logService, $this->logPage);
                    break;
                case 'settings':
                    $data += Manager::settings();
                    break;
                default:
                    $data += Manager::dashboard();
            }
        } catch (ApiException $e) {
            $data['error'] = $e->friendly();
        } catch (\Throwable $e) {
            report($e);
            $data['error'] = $e->getMessage();
        }
        return $data;
    }
}

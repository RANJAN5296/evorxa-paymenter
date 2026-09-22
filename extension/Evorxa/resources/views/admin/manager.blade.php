<x-filament-panels::page>
    @assets
        <link rel="stylesheet" href="{{ $assets }}manager.css?v={{ $v }}">
    @endassets

    <div class="evxa">
        {{-- Brand header --}}
        <div class="evxa-head">
            <div class="evxa-brand">
                <img class="evxa-logo evxa-logo-light" src="{{ $assets }}brand/evorxa-logo.png" alt="Evorxa">
                <img class="evxa-logo evxa-logo-dark" src="{{ $assets }}brand/evorxa-logo-white.png" alt="Evorxa">
                <span class="evxa-sub">Cloud for Paymenter</span>
            </div>
            <div class="evxa-links">
                <a href="https://console.evorxa.com" target="_blank" rel="noopener">Evorxa console</a>
                <a href="https://github.com/ScaleBit-Technologies/evorxa-paymenter" target="_blank" rel="noopener">Docs</a>
                <span>v{{ $version }}</span>
            </div>
        </div>

        {{-- Tabs --}}
        <nav class="evxa-tabs">
            @foreach ($tabs as $key => $label)
                <button type="button" wire:click="setTab('{{ $key }}')" class="evxa-tab {{ $tab === $key ? 'is-active' : '' }}">{{ $label }}</button>
            @endforeach
        </nav>

        @if ($error)
            <div class="evxa-alert evxa-alert-bad">{{ $error }}</div>
        @endif

        {{-- ============================================================ DASHBOARD --}}
        @if ($tab === 'dashboard' && isset($checks))
            <div class="evxa-stats">
                <div class="evxa-stat">
                    <div class="evxa-stat-label">Evorxa wallet</div>
                    <div class="evxa-stat-value">{{ $account['balance'] ?? '-' }}</div>
                    <div class="evxa-stat-sub">
                        @if (!empty($account['low']))<x-filament::badge color="warning">below your alert level</x-filament::badge> @endif
                        <a href="https://console.evorxa.com" target="_blank" rel="noopener">Top up</a>
                    </div>
                </div>
                <div class="evxa-stat">
                    <div class="evxa-stat-label">Next Evorxa renewal</div>
                    <div class="evxa-stat-value">{{ $account['renewalAmount'] ?? '-' }}</div>
                    <div class="evxa-stat-sub">{{ $account['renewalWhen'] ?? '' }}@if (isset($account)) · auto-renew {{ $account['autoRenew'] ? 'on' : 'off' }}@endif</div>
                </div>
                <div class="evxa-stat">
                    <div class="evxa-stat-label">Client servers</div>
                    <div class="evxa-stat-value">{{ $counts['active'] ?? 0 }} <small>active</small></div>
                    <div class="evxa-stat-sub">{{ ($counts['provisioning'] ?? 0) }} setting up · {{ ($counts['terminated'] ?? 0) }} closed</div>
                </div>
                <div class="evxa-stat">
                    <div class="evxa-stat-label">Needs attention</div>
                    <div class="evxa-stat-value">{{ count($attention) }}</div>
                    <div class="evxa-stat-sub">{{ count($attention) ? 'See below' : 'All good' }}</div>
                </div>
            </div>

            <div class="evxa-grid">
                <x-filament::section heading="Setup" description="{{ $progress['done'] }} of {{ $progress['total'] }} complete">
                    <div class="evxa-progress"><span style="width: {{ $progress['pct'] }}%"></span></div>
                    <ul class="evxa-checks">
                        @foreach ($checks as $check)
                            <li>
                                <span class="evxa-check {{ $check['ok'] ? 'is-ok' : 'is-warn' }}">{{ $check['ok'] ? '✓' : '!' }}</span>
                                <div class="evxa-check-text">
                                    <strong>{{ $check['label'] }}</strong>
                                    @if (!$check['ok'])<div class="evxa-muted">{{ $check['hint'] }}</div>@endif
                                </div>
                                @if (!empty($check['tab']))
                                    <x-filament::link tag="button" wire:click="setTab('{{ $check['tab'] }}')" size="sm">{{ $check['ok'] ? 'View' : 'Fix' }}</x-filament::link>
                                @elseif (!empty($check['url']))
                                    <x-filament::link :href="$check['url']" size="sm">{{ $check['ok'] ? 'View' : 'Fix' }}</x-filament::link>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </x-filament::section>

                <div class="evxa-col">
                    <x-filament::section heading="Monthly economics">
                        @if (($finance['count'] ?? 0) > 0)
                            <div class="evxa-money">
                                <div><span>Clients pay</span><strong>{{ $finance['revenue'] }}</strong></div>
                                <div><span>Evorxa cost</span><strong>{{ $finance['cost'] }}</strong></div>
                                <div><span>Margin</span><strong class="{{ $finance['negative'] ? 'evxa-bad' : 'evxa-ok' }}">{{ $finance['margin'] }} <small>{{ $finance['marginPct'] }}%</small></strong></div>
                            </div>
                            <p class="evxa-muted">Across {{ $finance['count'] }} active server(s), per month.@if (!$finance['costKnown']) Some Evorxa prices were unavailable.@endif</p>
                        @else
                            <p class="evxa-muted">No active Evorxa servers yet.</p>
                        @endif
                    </x-filament::section>

                    <x-filament::section heading="Needs attention">
                        @forelse ($attention as $item)
                            <div class="evxa-attn">
                                <a href="#" wire:click.prevent="openService({{ $item['service'] }})">Service #{{ $item['service'] }}</a>
                                <x-filament::badge color="{{ $item['state'] === 'failed' ? 'danger' : 'warning' }}">{{ $item['state'] }}</x-filament::badge>
                                <div class="evxa-muted">{{ $item['error'] }}</div>
                            </div>
                        @empty
                            <p class="evxa-muted">Nothing needs your attention.</p>
                        @endforelse
                    </x-filament::section>

                    <x-filament::section heading="Connection">
                        @if ($server)
                            <p>Connected as <strong>{{ $account['email'] ?? '...' }}</strong> via server <strong>{{ $server['name'] }}</strong>.</p>
                            <p class="evxa-muted">Evorxa renews each server from your wallet 5 days before it expires; the extension reactivates or stops servers to match what clients paid.</p>
                        @else
                            <p class="evxa-muted">No Evorxa server yet.</p>
                        @endif
                        <div class="evxa-actions">
                            <x-filament::button color="gray" size="sm" icon="ri-wallet-3-line" wire:click="checkWallet">Check wallet</x-filament::button>
                            <x-filament::button color="gray" size="sm" icon="ri-refresh-line" wire:click="runSync">Sync now</x-filament::button>
                            <x-filament::button color="gray" size="sm" icon="ri-download-2-line" wire:click="setTab('plans')">Import plans</x-filament::button>
                        </div>
                    </x-filament::section>
                </div>
            </div>

            <x-filament::section heading="Recent activity">
                @include('evorxa::admin.log-table', ['logs' => $recent])
                <x-filament::link tag="button" wire:click="setTab('log')" size="sm">View all</x-filament::link>
            </x-filament::section>
        @endif

        {{-- ============================================================ PLANS --}}
        @if ($tab === 'plans' && isset($plans))
            @if ($importReport)
                <x-filament::section heading="Import result">
                    <table class="evxa-table">
                        <thead><tr><th>Plan</th><th>Result</th><th>Prices</th><th>Note</th></tr></thead>
                        <tbody>
                            @foreach ($importReport as $r)
                                <tr>
                                    <td>{{ $r['plan'] }}</td>
                                    <td><x-filament::badge color="{{ $r['status'] === 'created' ? 'success' : ($r['status'] === 'error' ? 'danger' : 'gray') }}">{{ $r['status'] }}</x-filament::badge></td>
                                    <td>{{ isset($r['prices']) ? implode(' · ', $r['prices']) : '' }}</td>
                                    <td class="evxa-muted">{{ $r['message'] ?? '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="evxa-muted">Products are created hidden unless you unticked that option. Review them in Admin > Products, then unhide them.</p>
                </x-filament::section>
            @endif

            <x-filament::section heading="Evorxa plans" description="Live prices from your Evorxa account (what Evorxa charges your wallet). Tick the plans to sell, set your markup below and import. Plans you already sell show your monthly price and margin.">
                <div class="evxa-table-wrap">
                    <table class="evxa-table">
                        <thead><tr><th></th><th>Plan</th><th>Specs</th><th>Stock</th><th class="r">Monthly</th><th class="r">6 months</th><th class="r">Yearly</th><th>Your products</th></tr></thead>
                        <tbody>
                            @php $lastCategory = null; @endphp
                            @foreach ($plans as $p)
                                @if ($p['category'] !== $lastCategory)
                                    <tr class="evxa-group"><td colspan="8">{{ $p['category'] }}</td></tr>
                                    @php $lastCategory = $p['category']; @endphp
                                @endif
                                <tr>
                                    <td><input type="checkbox" class="evxa-cb" value="{{ $p['id'] }}" wire:model="import.packages"></td>
                                    <td><strong>{{ $p['name'] }}</strong> <span class="evxa-muted">#{{ $p['id'] }}</span></td>
                                    <td class="evxa-muted">{{ $p['specs'] }}</td>
                                    <td>@if ($p['stock'] > 0){{ $p['stock'] }}@else<span class="evxa-bad evxa-nowrap">sold out</span>@endif</td>
                                    <td class="r">${{ $p['monthly'] }}</td>
                                    <td class="r">${{ $p['semi'] }}</td>
                                    <td class="r">${{ $p['yearly'] }}</td>
                                    <td>
                                        @forelse ($p['products'] as $prod)
                                            <div>
                                                <a href="{{ $prod['url'] }}">{{ $prod['name'] }}</a>
                                                @if ($prod['hidden'])<x-filament::badge color="gray" size="xs">hidden</x-filament::badge>@endif
                                                @if ($prod['monthlyText'])<span class="evxa-muted">{{ $prod['monthlyText'] }}/mo</span>@endif
                                                @if ($prod['margin'])<x-filament::badge color="success" size="xs">{{ $prod['margin'] }} margin</x-filament::badge>@endif
                                            </div>
                                        @empty
                                            <span class="evxa-muted">-</span>
                                        @endforelse
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>

            <x-filament::section heading="Import selected plans">
                <div class="evxa-form">
                    <label>Category
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model="import.category_id">
                                <option value="">New category...</option>
                                @foreach ($categories as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                        <x-filament::input.wrapper class="evxa-mt">
                            <x-filament::input type="text" wire:model="import.new_category" placeholder="New category name, e.g. Cloud VPS" />
                        </x-filament::input.wrapper>
                    </label>
                    <label>Markup on Evorxa's price (%)
                        <x-filament::input.wrapper>
                            <x-filament::input type="number" step="1" wire:model="import.markup" />
                        </x-filament::input.wrapper>
                        <span class="evxa-muted">40% turns $2.99 into $4.99 with .99 rounding.</span>
                    </label>
                    <label>Price rounding
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model="import.rounding">
                                <option value="99">Up to .99 (4.19 → 4.99)</option>
                                <option value="whole">Up to a whole number (4.19 → 5.00)</option>
                                <option value="none">No rounding (4.19)</option>
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>
                    <div>Billing terms to sell
                        <label class="evxa-check-row"><x-filament::input.checkbox value="monthly" wire:model="import.cycles" /> Monthly</label>
                        <label class="evxa-check-row"><x-filament::input.checkbox value="semiannually" wire:model="import.cycles" /> Every 6 months (Evorxa gives 10% off)</label>
                        <label class="evxa-check-row"><x-filament::input.checkbox value="annually" wire:model="import.cycles" /> Yearly (Evorxa gives 20% off)</label>
                    </div>
                </div>
                <label class="evxa-check-row"><x-filament::input.checkbox wire:model="import.hidden" /> Create products hidden (review before publishing)</label>
                <p class="evxa-muted">Every product gets the Evorxa server settings, stock and upgrade paths. Clients choose the operating system or a one-click app and a hostname at checkout automatically.</p>
                <div class="evxa-actions">
                    <x-filament::button icon="ri-download-2-line" wire:click="runImport" wire:loading.attr="disabled">Import selected plans</x-filament::button>
                    <x-filament::button color="gray" icon="ri-stack-line" wire:click="syncStock">Sync stock now</x-filament::button>
                    <span class="evxa-muted">Plans already in the chosen category are skipped. Stock is kept in sync every hour.</span>
                </div>
            </x-filament::section>
        @endif

        {{-- ============================================================ SERVERS --}}
        @if ($tab === 'servers')
            @if ($serviceId)
                @include('evorxa::admin.service')
            @elseif (isset($servers))
                <x-filament::section heading="Servers" description="Servers in {{ implode(', ', $projectNames) ?: 'your Evorxa project' }}. Only projects used by this Paymenter are listed; your other Evorxa projects are never shown or touched.">
                    <form wire:submit.prevent="openService" class="evxa-inline">
                        <x-filament::input.wrapper>
                            <x-filament::input type="text" wire:model="lookup" placeholder="Service ID" />
                        </x-filament::input.wrapper>
                        <x-filament::button type="submit" color="gray">Open service</x-filament::button>
                    </form>
                    <div class="evxa-table-wrap">
                        <table class="evxa-table">
                            <thead><tr><th>Server</th><th>IP</th><th>Plan</th><th>Status</th><th>Paid until</th><th>Service</th></tr></thead>
                            <tbody>
                                @forelse ($servers as $s)
                                    <tr>
                                        <td><strong>{{ $s['hostname'] ?: $s['name'] }}</strong><div class="evxa-muted evxa-mono">{{ $s['id'] }} · {{ $s['name'] }}</div>@if ($s['flag'])<div class="evxa-bad">{{ $s['flag'] }}</div>@endif</td>
                                        <td class="evxa-mono">{{ $s['ip'] }}</td>
                                        <td>{{ $s['plan'] }}</td>
                                        <td><x-filament::badge color="{{ $s['status'] === 'running' ? 'success' : 'gray' }}">{{ $s['status'] }}</x-filament::badge>@if ($s['deletion'])<div class="evxa-muted">deletion {{ $s['deletion'] }}</div>@endif</td>
                                        <td>{{ $s['billedTill'] }}</td>
                                        <td>@if ($s['service'])<a href="#" wire:click.prevent="openService({{ $s['service'] }})">#{{ $s['service'] }}</a> <span class="evxa-muted">{{ $s['serviceStatus'] }}</span>@else<span class="evxa-muted">unmanaged</span>@endif</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="evxa-muted">No servers in this project yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if ($missing)
                        <div class="evxa-alert evxa-alert-bad">
                            Linked servers not found upstream:
                            @foreach ($missing as $m) service #{{ $m['service'] }} ({{ $m['instance'] }})@if (!$loop->last),@endif @endforeach
                        </div>
                    @endif
                    <p class="evxa-muted">To attach an existing server to a service, open the service and paste the server id into "Link existing server".</p>
                </x-filament::section>
            @endif
        @endif

        {{-- ============================================================ LOG --}}
        @if ($tab === 'log' && isset($logs))
            <x-filament::section heading="Activity log" description="Every action sent to Evorxa, by clients, admins and the scheduler. The Wallet column shows charges (positive) and refunds (negative).">
                <form wire:submit.prevent="filterLog" class="evxa-inline">
                    <x-filament::input.wrapper>
                        <x-filament::input type="number" wire:model="logService" placeholder="Service ID" />
                    </x-filament::input.wrapper>
                    <x-filament::button type="submit" color="gray">Filter</x-filament::button>
                </form>
                @include('evorxa::admin.log-table', ['logs' => $logs])
                <div class="evxa-actions">
                    @if ($pageNo > 1)<x-filament::button color="gray" size="sm" wire:click="logPageTo({{ $pageNo - 1 }})">Newer</x-filament::button>@endif
                    @if ($hasNext)<x-filament::button color="gray" size="sm" wire:click="logPageTo({{ $pageNo + 1 }})">Older</x-filament::button>@endif
                    <span class="evxa-muted">{{ $total }} entries</span>
                </div>
            </x-filament::section>
        @endif

        {{-- ============================================================ SETTINGS --}}
        @if ($tab === 'settings' && isset($labels))
            <form wire:submit.prevent="saveSettings" class="evxa-stack">
                <x-filament::section heading="New servers">
                    <div class="evxa-form">
                        <label>Evorxa project for client servers
                            <x-filament::input.wrapper>
                                <x-filament::input.select wire:model="settings.project_id">
                                    <option value="">Choose a project...</option>
                                    @foreach ($projects as $id => $name)
                                        <option value="{{ $id }}">{{ $name }} (#{{ $id }})</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                            <span class="evxa-muted">@if ($projectsError){{ $projectsError }}@else Only projects you own are listed. Use a dedicated project so client servers never mix with your own. Existing servers keep the project they were created in.@endif</span>
                        </label>
                        <label>Hostname suffix
                            <x-filament::input.wrapper>
                                <x-filament::input type="text" wire:model="settings.hostname_suffix" placeholder="example.com" />
                            </x-filament::input.wrapper>
                            <span class="evxa-muted">Servers are named vps&lt;service id&gt;.{{ $settings['hostname_suffix'] ?: 'example.com' }} or &lt;client choice&gt;.{{ $settings['hostname_suffix'] ?: 'example.com' }}. This also becomes the reverse DNS, so your brand shows instead of Evorxa's.</span>
                        </label>
                    </div>
                </x-filament::section>

                <x-filament::section heading="Client control panel" description="Choose what clients can do from their service page. Status, IPs, login details and billing are always shown.">
                    @foreach ($labels as $key => $label)
                        <label class="evxa-check-row"><x-filament::input.checkbox wire:model="settings.feature_{{ $key }}" /> {{ $label }}</label>
                    @endforeach
                    <label class="evxa-check-row"><x-filament::input.checkbox wire:model="settings.picker" /> Visual "operating system or app" picker with logos at checkout</label>
                </x-filament::section>

                <x-filament::section heading="Alerts">
                    <div class="evxa-form">
                        <label>Low wallet alert below (USD)
                            <x-filament::input.wrapper><x-filament::input type="number" step="1" wire:model="settings.low_balance" /></x-filament::input.wrapper>
                            <span class="evxa-muted">Checked daily. You are also alerted when the balance is below the renewals due in the next 7 days, or an order cannot be provisioned.</span>
                        </label>
                        <label>Alert when a build takes longer than (minutes)
                            <x-filament::input.wrapper><x-filament::input type="number" step="1" wire:model="settings.provision_timeout" /></x-filament::input.wrapper>
                            <span class="evxa-muted">Alerts go to the System Email Address (Admin > Settings > Mail).</span>
                        </label>
                    </div>
                    <label class="evxa-check-row"><x-filament::input.checkbox wire:model="settings.debug" /> Debug log: write every Evorxa API call to the Laravel log (token and passwords masked)</label>
                </x-filament::section>

                <x-filament::section heading="Client email">
                    <p class="evxa-muted">When a server is ready, clients receive the "server ready" notification with the IP, username and password.
                        @if ($emailTemplateUrl)<a href="{{ $emailTemplateUrl }}">Edit the template</a>.@endif</p>
                </x-filament::section>

                <div><x-filament::button type="submit" icon="ri-save-line">Save settings</x-filament::button></div>
            </form>
        @endif
    </div>
</x-filament-panels::page>

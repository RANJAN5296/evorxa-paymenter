@if (!$service)
    <x-filament::section heading="Service #{{ $serviceId }}">
        <p class="evxa-muted">This service was not found.</p>
        <x-filament::button color="gray" size="sm" wire:click="closeService">Back to servers</x-filament::button>
    </x-filament::section>
@else
    <x-filament::section heading="Service #{{ $service['id'] }} · {{ $service['product'] }}" description="{{ $service['client'] }} · {{ $service['status'] }}{{ $service['expires'] ? ' · paid until ' . $service['expires'] : '' }}">
        <div class="evxa-actions">
            <x-filament::button color="gray" size="sm" icon="ri-arrow-left-line" wire:click="closeService">Back to servers</x-filament::button>
            @if ($service['url'])<x-filament::button color="gray" size="sm" tag="a" :href="$service['url']" icon="ri-external-link-line">Open in Services</x-filament::button>@endif
        </div>

        @if (!$service['isEvorxa'])
            <p class="evxa-muted">This service does not use an Evorxa product.</p>
        @else
            @php $row = $service['row']; @endphp
            @if ($row)
                <dl class="evxa-dl">
                    <div><dt>Extension state</dt><dd><x-filament::badge color="{{ $row['state'] === 'active' ? 'success' : (in_array($row['state'], ['failed', 'unknown']) ? 'danger' : 'warning') }}">{{ $row['state'] }}</x-filament::badge></dd></div>
                    <div><dt>Evorxa server</dt><dd class="evxa-mono">{{ $row['instance'] ?: '-' }} <span class="evxa-muted">name {{ $row['name'] }} · project #{{ $row['project'] }}</span></dd></div>
                    <div><dt>Plan</dt><dd>{{ $row['plan'] }}</dd></div>
                    <div><dt>Image</dt><dd>{{ $row['image'] }}@if ($row['app']) <span class="evxa-muted">app: {{ $row['app'] }}</span>@endif</dd></div>
                    <div><dt>Upstream status</dt><dd>{{ $row['upstream'] ?: '-' }} <span class="evxa-muted">billing: {{ $row['billing'] ?: '-' }}</span></dd></div>
                    <div><dt>Hostname / IP</dt><dd>{{ $row['hostname'] }} <span class="evxa-mono">{{ $row['ip'] }}</span> <span class="evxa-muted">{{ $row['location'] }}</span></dd></div>
                    <div><dt>Login</dt><dd>{{ $row['username'] ?: '-' }} <span class="evxa-muted">{{ $row['hasPassword'] ? 'password stored (encrypted)' : 'no password stored' }}</span></dd></div>
                    <div><dt>Paid upstream until</dt><dd>{{ $row['billedTill'] ?: '-' }}@if ($row['deletion']) <x-filament::badge color="warning">deletion scheduled {{ $row['deletion'] }}</x-filament::badge>@endif</dd></div>
                    <div><dt>Last sync</dt><dd>{{ $row['lastSync'] ?: '-' }}@if ($row['readyEmail']) <span class="evxa-muted">ready email sent {{ $row['readyEmail'] }}</span>@endif</dd></div>
                    @if ($row['error'])<div><dt>Last error</dt><dd class="evxa-bad">{{ $row['error'] }}</dd></div>@endif
                </dl>

                <div class="evxa-actions">
                    <x-filament::button color="gray" size="sm" icon="ri-play-line" wire:click="serviceAction('boot')" wire:confirm="Boot this server?">Boot</x-filament::button>
                    <x-filament::button color="gray" size="sm" icon="ri-restart-line" wire:click="serviceAction('restart')" wire:confirm="Restart this server?">Restart</x-filament::button>
                    <x-filament::button color="gray" size="sm" icon="ri-stop-line" wire:click="serviceAction('shutdown')" wire:confirm="Shut down this server?">Shut down</x-filament::button>
                    <x-filament::button color="danger" size="sm" icon="ri-shut-down-line" wire:click="serviceAction('poweroff')" wire:confirm="Force this server off?">Power off</x-filament::button>
                    <x-filament::button color="gray" size="sm" icon="ri-key-2-line" wire:click="serviceAction('reset_password')" wire:confirm="Reset the root password?">Reset root password</x-filament::button>
                    <x-filament::button color="gray" size="sm" icon="ri-refresh-line" wire:click="serviceAction('sync')">Sync now</x-filament::button>
                    <x-filament::button color="gray" size="sm" wire:click="serviceAction('reactivate')" wire:confirm="Reactivate the server upstream? This may charge your Evorxa wallet.">Reactivate upstream</x-filament::button>
                    <x-filament::button color="gray" size="sm" wire:click="serviceAction('cancel_deletion')">Cancel upstream deletion</x-filament::button>
                </div>
            @else
                <p class="evxa-muted">No Evorxa server is linked to this service yet. It is created when the invoice is paid.</p>
            @endif

            <form wire:submit.prevent="serviceAction('link')" class="evxa-inline">
                <x-filament::input.wrapper>
                    <x-filament::input type="text" wire:model="linkInstance" placeholder="Evorxa server id (or: unlink)" />
                </x-filament::input.wrapper>
                <x-filament::button type="submit" color="gray" size="sm">Link existing server</x-filament::button>
            </form>
            <p class="evxa-muted">Attach a server that already exists in one of your Evorxa projects. Nothing is created or charged.</p>

            <h3 class="evxa-h3">Recent activity</h3>
            @include('evorxa::admin.log-table', ['logs' => $service['logs']])
        @endif
    </x-filament::section>
@endif

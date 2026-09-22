<div class="evxa-table-wrap">
    <table class="evxa-table">
        <thead><tr><th>When</th><th>Service</th><th>By</th><th>Action</th><th>Details</th><th class="r">Wallet</th></tr></thead>
        <tbody>
            @forelse ($logs as $log)
                <tr>
                    <td class="evxa-nowrap">{{ $log['when'] }}</td>
                    <td>@if ($log['service'])<a href="#" wire:click.prevent="openService({{ $log['service'] }})">#{{ $log['service'] }}</a>@else - @endif</td>
                    <td class="evxa-muted">{{ $log['actor'] }}</td>
                    <td><x-filament::badge color="{{ $log['ok'] ? 'success' : 'danger' }}" size="sm">{{ $log['action'] }}</x-filament::badge></td>
                    <td>{{ $log['message'] }}</td>
                    <td class="r evxa-nowrap">{{ $log['amount'] }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="evxa-muted">No activity yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

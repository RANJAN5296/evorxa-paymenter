<section class="evx-card evx-notice evx-notice-{{ ($evx['state'] ?? '') }}">
    @if(($evx['state'] ?? null) == 'suspended')
        <i class="fas fa-lock" aria-hidden="true"></i>
        <h4>{{ ($evx['t']['suspended_title'] ?? '') }}</h4>
        <p class="evx-muted">{{ ($evx['t']['suspended_text'] ?? '') }}</p>
        <a href="{{ route('invoices') }}" class="btn btn-primary">{{ ($evx['t']['view_invoices'] ?? '') }}</a>
    @elseif(($evx['state'] ?? null) == 'pending')
        <i class="fas fa-hourglass-half" aria-hidden="true"></i>
        <h4>{{ ($evx['t']['pendingpay_title'] ?? '') }}</h4>
        <p class="evx-muted">{{ ($evx['t']['pendingpay_text'] ?? '') }}</p>
    @elseif(($evx['state'] ?? null) == 'failed')
        <i class="fas fa-tools" aria-hidden="true"></i>
        <h4>{{ ($evx['t']['failed_title'] ?? '') }}</h4>
        <p class="evx-muted">{{ ($evx['t']['failed_text'] ?? '') }}</p>
        <a href="{{ route('tickets.create') }}" class="btn btn-default">{{ ($evx['t']['contact_support'] ?? '') }}</a>
    @elseif(($evx['state'] ?? null) == 'closed')
        <i class="fas fa-archive" aria-hidden="true"></i>
        <h4>{{ ($evx['t']['closed_title'] ?? '') }}</h4>
        <p class="evx-muted">{{ ($evx['t']['closed_text'] ?? '') }}</p>
    @else
        <i class="fas fa-server" aria-hidden="true"></i>
        <h4>{{ ($evx['t']['none_title'] ?? '') }}</h4>
        <p class="evx-muted">{{ ($evx['t']['none_text'] ?? '') }}</p>
    @endif
</section>

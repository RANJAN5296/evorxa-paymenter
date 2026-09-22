<section class="evx-card evx-hero">
    <div class="evx-hero-main">
        @if(($evx['server']['osLogo'] ?? null))
            <span class="evx-os evx-os-img" aria-hidden="true"><img src="{{ ($evx['server']['osLogo'] ?? '') }}" alt="" loading="lazy"></span>
        @else
            <span class="evx-os evx-os-{{ ($evx['server']['osFamily'] ?? '') }}" aria-hidden="true">{{ ($evx['server']['osLetter'] ?? '') }}</span>
        @endif
        <div class="evx-hero-text">
            <div class="evx-hero-title">
                <h3 class="evx-hostname" title="{{ ($evx['server']['hostname'] ?? '') }}">{!! ($evx['server']['hostname'] ?? null) ? e(($evx['server']['hostname'] ?? '')) : e(($evx['product'] ?? '')) !!}</h3>
                <span class="evx-pill evx-tone-{{ ($evx['status']['tone'] ?? '') }}" data-evx="status">
                    <span class="evx-dot"></span><span data-evx="status-label">{{ ($evx['status']['label'] ?? '') }}</span>
                </span>
            </div>
            <div class="evx-hero-meta">
                @if(($evx['server']['ip'] ?? null))
                    <button type="button" class="evx-chip" data-copy="{{ ($evx['server']['ip'] ?? '') }}" title="{{ ($evx['t']['copy'] ?? '') }}">
                        <i class="far fa-copy" aria-hidden="true"></i><span class="evx-mono" data-evx="ip">{{ ($evx['server']['ip'] ?? '') }}</span>
                    </button>
                @endif
                @if(($evx['server']['os'] ?? null))<span class="evx-meta"><i class="fas {!! ($evx['app'] ?? null) ? 'fa-cube' : 'fa-compact-disc' !!}" aria-hidden="true"></i>{{ ($evx['server']['os'] ?? '') }}</span>@endif
                {!! ($evx['server']['location'] ?? null) ? '<span class="evx-meta"><i class="fas fa-map-marker-alt" aria-hidden="true"></i>' . e(($evx['server']['location'] ?? '')) . '</span>' : '' !!}
            </div>
        </div>
    </div>
    @if(($evx['state'] ?? null) == 'active' && ($evx['features']['power'] ?? null))
        <div class="evx-power" role="group" aria-label="{{ ($evx['t']['power_controls'] ?? '') }}">
            <button type="button" class="btn btn-default evx-btn" data-power="boot" data-when="off">
                <i class="fas fa-play" aria-hidden="true"></i><span>{{ ($evx['t']['power_boot'] ?? '') }}</span>
            </button>
            <button type="button" class="btn btn-default evx-btn" data-power="restart" data-when="on">
                <i class="fas fa-redo" aria-hidden="true"></i><span>{{ ($evx['t']['power_restart'] ?? '') }}</span>
            </button>
            <button type="button" class="btn btn-default evx-btn" data-power="shutdown" data-when="on">
                <i class="fas fa-stop" aria-hidden="true"></i><span>{{ ($evx['t']['power_shutdown'] ?? '') }}</span>
            </button>
            <button type="button" class="btn btn-default evx-btn evx-btn-quiet" data-power="poweroff" data-when="on" title="{{ ($evx['t']['power_poweroff_hint'] ?? '') }}">
                <i class="fas fa-power-off" aria-hidden="true"></i><span>{{ ($evx['t']['power_poweroff'] ?? '') }}</span>
            </button>
        </div>
    @endif
</section>

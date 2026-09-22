<div class="evx-grid">
    <section class="evx-card">
        <header class="evx-card-head">
            <h4><i class="fas fa-terminal" aria-hidden="true"></i>{{ ($evx['t']['access_title'] ?? '') }}</h4>
        </header>
        <div class="evx-field">
            <div class="evx-label">{!! ($evx['server']['windows'] ?? null) ? e(($evx['t']['connect_rdp'] ?? '')) : e(($evx['t']['connect_ssh'] ?? '')) !!}</div>
            <div class="evx-value">
                <code class="evx-mono evx-grow">{{ ($evx['server']['connect'] ?? '') }}</code>
                <button type="button" class="evx-icon-btn" data-copy="{{ ($evx['server']['connect'] ?? '') }}" title="{{ ($evx['t']['copy'] ?? '') }}"><i class="far fa-copy" aria-hidden="true"></i></button>
            </div>
        </div>
        <div class="evx-field">
            <div class="evx-label">{{ ($evx['t']['username'] ?? '') }}</div>
            <div class="evx-value">
                <code class="evx-mono evx-grow">{{ ($evx['server']['username'] ?? '') }}</code>
                <button type="button" class="evx-icon-btn" data-copy="{{ ($evx['server']['username'] ?? '') }}" title="{{ ($evx['t']['copy'] ?? '') }}"><i class="far fa-copy" aria-hidden="true"></i></button>
            </div>
        </div>
        <div class="evx-field">
            <div class="evx-label">{{ ($evx['t']['password'] ?? '') }}</div>
            <div class="evx-value">
                <code class="evx-mono evx-grow" data-evx="password">&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;</code>
                @if(($evx['server']['hasPassword'] ?? null))
                    <button type="button" class="evx-icon-btn" data-action="password-toggle" title="{{ ($evx['t']['show'] ?? '') }}"><i class="far fa-eye" aria-hidden="true"></i></button>
                    <button type="button" class="evx-icon-btn" data-action="password-copy" title="{{ ($evx['t']['copy'] ?? '') }}"><i class="far fa-copy" aria-hidden="true"></i></button>
                @endif
            </div>
            @if(($evx['features']['password'] ?? null))
                <button type="button" class="evx-link" data-action="password-reset"><i class="fas fa-key" aria-hidden="true"></i>{{ ($evx['t']['password_reset'] ?? '') }}</button>
            @endif
        </div>
    </section>

    <section class="evx-card">
        <header class="evx-card-head">
            <h4><i class="fas fa-network-wired" aria-hidden="true"></i>{{ ($evx['t']['network_title'] ?? '') }}</h4>
        </header>
        @if(($evx['ips'] ?? null))
            <ul class="evx-ips">
                @foreach($evx['ips'] ?? [] as $ip)
                    <li>
                        <div class="evx-value">
                            <code class="evx-mono evx-grow">{{ ($ip['address'] ?? '') }}</code>
                            <span class="evx-tag">{{ ($ip['type'] ?? '') }}</span>
                            {!! ($ip['primary'] ?? null) ? '<span class="evx-tag evx-tag-accent">' . e(($evx['t']['primary'] ?? '')) . '</span>' : '' !!}
                            <button type="button" class="evx-icon-btn" data-copy="{{ ($ip['address'] ?? '') }}" title="{{ ($evx['t']['copy'] ?? '') }}"><i class="far fa-copy" aria-hidden="true"></i></button>
                        </div>
                        <div class="evx-sub evx-rdns" data-rdns-row="{{ ($ip['address'] ?? '') }}">
                            {{ ($evx['t']['rdns'] ?? '') }}: <span class="evx-mono" data-rdns-value>{!! ($ip['rdns'] ?? null) ? e(($ip['rdns'] ?? '')) : '&ndash;' !!}</span>
                            {!! ($evx['features']['rdns'] ?? null) ? '<button type="button" class="evx-link evx-rdns-edit" data-action="rdns-edit"><i class="fas fa-pen" aria-hidden="true"></i>' . e(($evx['t']['edit'] ?? '')) . '</button>' : '' !!}
                        </div>
                        @if(($evx['features']['rdns'] ?? null))
                            <form class="evx-rdns-form" data-rdns-form="{{ ($ip['address'] ?? '') }}" hidden autocomplete="off">
                                <div class="evx-inline">
                                    <input type="text" name="rdns" class="form-control" value="{{ ($ip['rdns'] ?? '') }}" placeholder="host.example.com" aria-label="{{ ($evx['t']['rdns'] ?? '') }}" spellcheck="false">
                                    <button type="submit" class="btn btn-primary">{{ ($evx['t']['save'] ?? '') }}</button>
                                    <button type="button" class="btn btn-default" data-action="rdns-cancel">{{ ($evx['t']['cancel'] ?? '') }}</button>
                                </div>
                                <div class="evx-sub">{{ ($evx['t']['rdns_hint'] ?? '') }}</div>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        @else
            <p class="evx-muted">{{ ($evx['t']['no_ips'] ?? '') }}</p>
        @endif
        <div class="evx-protect">
            <i class="fas fa-shield-alt" aria-hidden="true"></i>
            <span>{{ ($evx['t']['ddos_always_on'] ?? '') }}</span>
        </div>
    </section>
</div>

@if(($evx['app'] ?? null))
<section class="evx-card evx-app" data-evx="app">
    <header class="evx-card-head">
        <h4>{!! ($evx['app']['logo'] ?? null) ? '<img class="evx-app-logo" src="' . e(($evx['app']['logo'] ?? '')) . '" alt="" aria-hidden="true">' : '<i class="fas fa-cube" aria-hidden="true"></i>' !!}{{ ($evx['app']['name'] ?? '') }}</h4>
        <button type="button" class="btn btn-default btn-sm" data-action="app-reveal">{{ ($evx['t']['app_show_login'] ?? '') }}</button>
    </header>
    <p class="evx-muted">{{ ($evx['t']['app_intro'] ?? '') }}</p>
    <div class="evx-app-details" data-evx="app-details" hidden>
        <div class="evx-field">
            <div class="evx-label">{{ ($evx['t']['app_url'] ?? '') }}</div>
            <div class="evx-value"><a class="evx-mono evx-grow" data-evx="app-url" href="#" target="_blank" rel="noopener noreferrer"></a></div>
        </div>
        <div class="evx-field">
            <div class="evx-label">{{ ($evx['t']['username'] ?? '') }}</div>
            <div class="evx-value"><code class="evx-mono evx-grow" data-evx="app-user"></code><button type="button" class="evx-icon-btn" data-copy-from="app-user" title="{{ ($evx['t']['copy'] ?? '') }}"><i class="far fa-copy" aria-hidden="true"></i></button></div>
        </div>
        <div class="evx-field">
            <div class="evx-label">{{ ($evx['t']['password'] ?? '') }}</div>
            <div class="evx-value"><code class="evx-mono evx-grow" data-evx="app-pass"></code><button type="button" class="evx-icon-btn" data-copy-from="app-pass" title="{{ ($evx['t']['copy'] ?? '') }}"><i class="far fa-copy" aria-hidden="true"></i></button></div>
        </div>
        <p class="evx-note" data-evx="app-note" hidden></p>
    </div>
</section>
@endif

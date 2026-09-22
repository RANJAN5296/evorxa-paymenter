<section class="evx-card evx-tabs-card">
    <nav class="evx-tabs" role="tablist">
        @if(($evx['features']['graphs'] ?? null))
            <button type="button" role="tab" class="evx-tab{!! ($evx['firstTab'] ?? null) == 'usage' ? ' is-active' : '' !!}" data-tab="usage"><i class="fas fa-chart-area" aria-hidden="true"></i>{{ ($evx['t']['tab_usage'] ?? '') }}</button>
        @endif
        @if(($evx['features']['ddos'] ?? null))
            <button type="button" role="tab" class="evx-tab{!! ($evx['firstTab'] ?? null) == 'ddos' ? ' is-active' : '' !!}" data-tab="ddos"><i class="fas fa-shield-alt" aria-hidden="true"></i>{{ ($evx['t']['tab_ddos'] ?? '') }}</button>
        @endif
        @if(($evx['features']['firewall'] ?? null))
            <button type="button" role="tab" class="evx-tab{!! ($evx['firstTab'] ?? null) == 'firewall' ? ' is-active' : '' !!}" data-tab="firewall"><i class="fas fa-fire-alt" aria-hidden="true"></i>{{ ($evx['t']['tab_firewall'] ?? '') }}</button>
        @endif
        @if(($evx['features']['reinstall'] ?? null))
            <button type="button" role="tab" class="evx-tab{!! ($evx['firstTab'] ?? null) == 'reinstall' ? ' is-active' : '' !!}" data-tab="reinstall"><i class="fas fa-sync-alt" aria-hidden="true"></i>{{ ($evx['t']['tab_reinstall'] ?? '') }}</button>
        @endif
    </nav>

    @if(($evx['features']['graphs'] ?? null))
    <div class="evx-panel{!! ($evx['firstTab'] ?? null) == 'usage' ? ' is-active' : '' !!}" data-panel="usage" role="tabpanel">
        <div class="evx-panel-head">
            <p class="evx-muted">{{ ($evx['t']['usage_intro'] ?? '') }}</p>
            <div class="evx-seg" data-evx="usage-period">
                <button type="button" data-period="1h" class="is-active">{{ ($evx['t']['period_1h'] ?? '') }}</button>
                <button type="button" data-period="6h">{{ ($evx['t']['period_6h'] ?? '') }}</button>
                <button type="button" data-period="24h">{{ ($evx['t']['period_24h'] ?? '') }}</button>
            </div>
        </div>
        <div class="evx-stats">
            <div class="evx-stat"><div class="evx-stat-label">{{ ($evx['t']['stat_cpu'] ?? '') }}</div><div class="evx-stat-value" data-evx="stat-cpu"><span class="evx-skel evx-skel-num"></span></div><div class="evx-bar"><span data-evx="bar-cpu"></span></div></div>
            <div class="evx-stat"><div class="evx-stat-label">{{ ($evx['t']['stat_memory'] ?? '') }}</div><div class="evx-stat-value" data-evx="stat-mem"><span class="evx-skel evx-skel-num"></span></div><div class="evx-bar"><span data-evx="bar-mem"></span></div><div class="evx-stat-sub" data-evx="stat-mem-text"></div></div>
            <div class="evx-stat"><div class="evx-stat-label">{{ ($evx['t']['stat_disk'] ?? '') }}</div><div class="evx-stat-value" data-evx="stat-disk"><span class="evx-skel evx-skel-num"></span></div><div class="evx-bar"><span data-evx="bar-disk"></span></div><div class="evx-stat-sub" data-evx="stat-disk-text"></div></div>
            <div class="evx-stat"><div class="evx-stat-label">{{ ($evx['t']['stat_traffic'] ?? '') }}</div><div class="evx-stat-value evx-stat-small"><span>&darr; <span data-evx="stat-rx"><span class="evx-skel evx-skel-inline"></span></span></span> <span>&uarr; <span data-evx="stat-tx"><span class="evx-skel evx-skel-inline"></span></span></span></div><div class="evx-stat-sub">{{ ($evx['t']['stat_traffic_hint'] ?? '') }}</div></div>
        </div>
        <div class="evx-charts">
            <figure class="evx-chart"><figcaption>{{ ($evx['t']['chart_cpu'] ?? '') }}</figcaption><div class="evx-chart-box" data-chart="cpu"><div class="evx-skel evx-skel-chart"></div></div></figure>
            <figure class="evx-chart"><figcaption>{{ ($evx['t']['chart_memory'] ?? '') }}</figcaption><div class="evx-chart-box" data-chart="mem"><div class="evx-skel evx-skel-chart"></div></div></figure>
            <figure class="evx-chart evx-chart-wide"><figcaption>{{ ($evx['t']['chart_network'] ?? '') }}</figcaption><div class="evx-chart-box" data-chart="net"><div class="evx-skel evx-skel-chart"></div></div></figure>
        </div>
    </div>
    @endif

    @if(($evx['features']['ddos'] ?? null))
    <div class="evx-panel{!! ($evx['firstTab'] ?? null) == 'ddos' ? ' is-active' : '' !!}" data-panel="ddos" role="tabpanel">
        <div class="evx-banner evx-banner-ok">
            <i class="fas fa-shield-alt" aria-hidden="true"></i>
            <div><strong>{{ ($evx['t']['ddos_title'] ?? '') }}</strong><div>{{ ($evx['t']['ddos_intro'] ?? '') }}</div></div>
        </div>
        <div data-evx="ddos-unsupported" class="evx-empty" hidden>{{ ($evx['t']['ddos_unsupported'] ?? '') }}</div>
        <div data-evx="ddos-body">
            <div class="evx-panel-head">
                <h5>{{ ($evx['t']['ddos_traffic'] ?? '') }}</h5>
                <div class="evx-seg" data-evx="ddos-period">
                    <button type="button" data-period="live" class="is-active">{{ ($evx['t']['period_live'] ?? '') }}</button>
                    <button type="button" data-period="1h">{{ ($evx['t']['period_1h'] ?? '') }}</button>
                    <button type="button" data-period="1d">{{ ($evx['t']['period_1d'] ?? '') }}</button>
                    <button type="button" data-period="1w">{{ ($evx['t']['period_1w'] ?? '') }}</button>
                </div>
            </div>
            <div class="evx-chart"><div class="evx-chart-box" data-chart="ddos"><div class="evx-skel evx-skel-chart"></div></div></div>

            <h5 class="evx-h5">{{ ($evx['t']['ddos_attacks'] ?? '') }}</h5>
            <div class="evx-table-wrap">
                <table class="evx-table">
                    <thead><tr><th>{{ ($evx['t']['ddos_started'] ?? '') }}</th><th>{{ ($evx['t']['ddos_duration'] ?? '') }}</th><th>{{ ($evx['t']['ddos_vectors'] ?? '') }}</th><th>{{ ($evx['t']['ddos_peak'] ?? '') }}</th></tr></thead>
                    <tbody data-evx="ddos-incidents"><tr class="evx-skel-row"><td colspan="4"><span class="evx-skel evx-skel-line"></span></td></tr><tr class="evx-skel-row"><td colspan="4"><span class="evx-skel evx-skel-line"></span></td></tr><tr class="evx-skel-row"><td colspan="4"><span class="evx-skel evx-skel-line"></span></td></tr></tbody>
                </table>
            </div>

            <h5 class="evx-h5">{{ ($evx['t']['ddos_alerts'] ?? '') }}</h5>
            <form class="evx-form" data-evx="ddos-form" autocomplete="off">
                <label class="evx-switch">
                    <input type="checkbox" name="email" value="1">
                    <span class="evx-switch-ui" aria-hidden="true"></span>
                    <span>{{ ($evx['t']['ddos_email'] ?? '') }}</span>
                </label>
                <button type="submit" class="btn btn-primary">{{ ($evx['t']['save'] ?? '') }}</button>
            </form>
        </div>
    </div>
    @endif

    @if(($evx['features']['firewall'] ?? null))
    <div class="evx-panel{!! ($evx['firstTab'] ?? null) == 'firewall' ? ' is-active' : '' !!}" data-panel="firewall" role="tabpanel">
        <div class="evx-banner">
            <i class="fas fa-fire-alt" aria-hidden="true"></i>
            <div><strong>{{ ($evx['t']['fw_title'] ?? '') }} <span class="evx-tag" data-evx="fw-state"></span></strong><div>{{ ($evx['t']['fw_intro'] ?? '') }}</div></div>
        </div>
        <div data-evx="fw-unavailable" class="evx-empty" hidden>{{ ($evx['t']['fw_unavailable'] ?? '') }}</div>
        <div data-evx="fw-body">
            <form class="evx-form evx-policy" data-evx="fw-policy">
                <label class="evx-switch evx-policy-switch">
                    <input type="checkbox" name="enabled" value="1">
                    <span class="evx-switch-ui" aria-hidden="true"></span>
                    <span><strong>{{ ($evx['t']['fw_enabled'] ?? '') }}</strong><span class="evx-sub">{{ ($evx['t']['fw_enabled_hint'] ?? '') }}</span></span>
                </label>
                <div class="evx-form-row">
                    <label class="evx-label" for="evx-fw-in">{{ ($evx['t']['fw_default_in'] ?? '') }}</label>
                    <select id="evx-fw-in" name="in" class="form-control"><option value="allow">{{ ($evx['t']['fw_allow_all'] ?? '') }}</option><option value="block">{{ ($evx['t']['fw_block_all'] ?? '') }}</option></select>
                </div>
                <div class="evx-form-row">
                    <label class="evx-label" for="evx-fw-out">{{ ($evx['t']['fw_default_out'] ?? '') }}</label>
                    <select id="evx-fw-out" name="out" class="form-control"><option value="allow">{{ ($evx['t']['fw_allow_all'] ?? '') }}</option><option value="block">{{ ($evx['t']['fw_block_all'] ?? '') }}</option></select>
                </div>
                <button type="submit" class="btn btn-default">{{ ($evx['t']['fw_save_policy'] ?? '') }}</button>
            </form>

            <h5 class="evx-h5">{{ ($evx['t']['fw_rules'] ?? '') }}</h5>
            <p class="evx-sub evx-order-hint"><i class="fas fa-grip-vertical" aria-hidden="true"></i> {{ ($evx['t']['fw_order_hint'] ?? '') }}</p>
            <div class="evx-table-wrap">
                <table class="evx-table">
                    <thead><tr><th class="evx-grip-col"></th><th>{{ ($evx['t']['fw_action'] ?? '') }}</th><th>{{ ($evx['t']['fw_direction'] ?? '') }}</th><th>{{ ($evx['t']['fw_protocol'] ?? '') }}</th><th>{{ ($evx['t']['fw_port'] ?? '') }}</th><th>{{ ($evx['t']['fw_source'] ?? '') }}</th><th></th></tr></thead>
                    <tbody data-evx="fw-rules"><tr class="evx-skel-row"><td colspan="7"><span class="evx-skel evx-skel-line"></span></td></tr><tr class="evx-skel-row"><td colspan="7"><span class="evx-skel evx-skel-line"></span></td></tr><tr class="evx-skel-row"><td colspan="7"><span class="evx-skel evx-skel-line"></span></td></tr></tbody>
                </table>
            </div>

            <h5 class="evx-h5">{{ ($evx['t']['fw_add'] ?? '') }}</h5>
            <div class="evx-presets">
                <span class="evx-muted">{{ ($evx['t']['fw_quick'] ?? '') }}</span>
                <button type="button" class="evx-chip" data-preset="allow,tcp,22">SSH 22</button>
                <button type="button" class="evx-chip" data-preset="allow,tcp,80">HTTP 80</button>
                <button type="button" class="evx-chip" data-preset="allow,tcp,443">HTTPS 443</button>
                <button type="button" class="evx-chip" data-preset="allow,tcp,3389">RDP 3389</button>
            </div>
            <form class="evx-form evx-rule-form" data-evx="fw-add" autocomplete="off">
                <select name="rule_action" class="form-control" aria-label="{{ ($evx['t']['fw_action'] ?? '') }}"><option value="allow">{{ ($evx['t']['fw_allow'] ?? '') }}</option><option value="block">{{ ($evx['t']['fw_block'] ?? '') }}</option></select>
                <select name="direction" class="form-control" aria-label="{{ ($evx['t']['fw_direction'] ?? '') }}"><option value="in">{{ ($evx['t']['fw_in'] ?? '') }}</option><option value="out">{{ ($evx['t']['fw_out'] ?? '') }}</option></select>
                <select name="protocol" class="form-control" aria-label="{{ ($evx['t']['fw_protocol'] ?? '') }}"><option value="tcp">TCP</option><option value="udp">UDP</option><option value="any">{{ ($evx['t']['fw_any'] ?? '') }}</option></select>
                <input type="text" name="port" class="form-control" inputmode="numeric" placeholder="{{ ($evx['t']['fw_port_ph'] ?? '') }}" aria-label="{{ ($evx['t']['fw_port'] ?? '') }}">
                <input type="text" name="source" class="form-control" placeholder="{{ ($evx['t']['fw_source_ph'] ?? '') }}" aria-label="{{ ($evx['t']['fw_source'] ?? '') }}">
                <button type="submit" class="btn btn-primary">{{ ($evx['t']['fw_add_btn'] ?? '') }}</button>
            </form>
        </div>
    </div>
    @endif

    @if(($evx['features']['reinstall'] ?? null))
    <div class="evx-panel{!! ($evx['firstTab'] ?? null) == 'reinstall' ? ' is-active' : '' !!}" data-panel="reinstall" role="tabpanel">
        <div class="evx-banner evx-banner-warn">
            <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
            <div><strong>{{ ($evx['t']['reinstall_warn_title'] ?? '') }}</strong><div>{{ ($evx['t']['reinstall_warn'] ?? '') }}</div></div>
        </div>
        <div class="evx-seg evx-seg-wide" data-evx="reinstall-mode" hidden>
            <button type="button" data-mode="os" class="is-active">{{ ($evx['t']['reinstall_os'] ?? '') }}</button>
            <button type="button" data-mode="app">{{ ($evx['t']['reinstall_app'] ?? '') }}</button>
        </div>
        <div class="evx-picker" data-evx="os-list"><div class="evx-skel evx-skel-card"></div><div class="evx-skel evx-skel-card"></div><div class="evx-skel evx-skel-card"></div><div class="evx-skel evx-skel-card"></div><div class="evx-skel evx-skel-card"></div><div class="evx-skel evx-skel-card"></div></div>
        <div class="evx-picker evx-apps" data-evx="app-list" hidden></div>
        <form class="evx-form" data-evx="reinstall-form">
            <label class="evx-check">
                <input type="checkbox" name="confirm" value="1">
                <span>{{ ($evx['t']['reinstall_confirm'] ?? '') }} <strong class="evx-mono">{{ ($evx['server']['hostname'] ?? '') }}</strong></span>
            </label>
            <button type="submit" class="btn btn-danger" disabled>{{ ($evx['t']['reinstall_btn'] ?? '') }}</button>
        </form>
    </div>
    @endif
</section>

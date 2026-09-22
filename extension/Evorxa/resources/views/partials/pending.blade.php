<section class="evx-card evx-pending" data-evx="pending">
    <div class="evx-spinner" aria-hidden="true"></div>
    <h4>{{ ($evx['t']['pending_title'] ?? '') }}</h4>
    <p class="evx-muted">{{ ($evx['t']['pending_text'] ?? '') }}</p>
    <ol class="evx-steps" data-evx="steps">
        <li class="is-done" data-step="order"><span class="evx-step-dot" aria-hidden="true"></span><span>{{ ($evx['t']['step_order'] ?? '') }}</span></li>
        <li class="{!! ($evx['phase'] ?? null) == 'install' ? 'is-done' : 'is-active' !!}" data-step="build"><span class="evx-step-dot" aria-hidden="true"></span><span>{{ ($evx['t']['step_build'] ?? '') }}</span></li>
        <li class="{!! ($evx['phase'] ?? null) == 'install' ? 'is-active' : '' !!}" data-step="install"><span class="evx-step-dot" aria-hidden="true"></span><span>{!! ($evx['app'] ?? null) ? e(($evx['t']['step_app'] ?? '')) : e(($evx['t']['step_os'] ?? '')) !!}</span></li>
        <li data-step="ready"><span class="evx-step-dot" aria-hidden="true"></span><span>{{ ($evx['t']['step_ready'] ?? '') }}</span></li>
    </ol>
</section>

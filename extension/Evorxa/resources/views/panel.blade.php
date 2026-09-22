{{--
    Evorxa Cloud - client server panel (the "Server" tab of a Paymenter service).

    All data comes from one variable, $evx, built by Services/Panel.php:
      $evx['state']     pending | provisioning | active | suspended | closed | failed | none
      $evx['status']    key, label, tone (ok|warn|off|busy|bad), running, busy
      $evx['server']    hostname, ip, os, osFamily, osLetter, osLogo, windows, username, hasPassword, location, connect
      $evx['specs'][]   icon, label, value
      $evx['ips'][]     address, type, rdns, primary
      $evx['app']       slug, name, note, logo (or null)
      $evx['features']  power, password, reinstall, apps, graphs, ddos, firewall, rdns
      $evx['t']         translated strings (resources/lang, overridable in storage/app/evorxa/lang/)
    Markup is free to change; the script only relies on the data-evx / data-action / data-power hooks.
    To restyle it from your theme, copy this folder to themes/<theme>/views/vendor/... or override the --evx-* CSS variables.
--}}
<div wire:ignore class="evx-host">
    <link rel="stylesheet" href="{{ $evx['assets'] }}evorxa.css?v={{ $evx['v'] }}">
    <link rel="stylesheet" href="{{ $evx['assets'] }}icons.css?v={{ $evx['v'] }}">
    <link rel="stylesheet" href="{{ $evx['assets'] }}paymenter.css?v={{ $evx['v'] }}">
    <div class="evx" id="evx" data-state="{{ $evx['state'] }}" @if ($evx['rtl']) dir="rtl" @endif>

        @include('evorxa::partials.header')

        @if ($evx['state'] == 'active')
            @include('evorxa::partials.specs')
            @include('evorxa::partials.access')
            @if ($evx['hasTabs'])
                @include('evorxa::partials.tabs')
            @endif
        @elseif ($evx['state'] == 'provisioning')
            @include('evorxa::partials.pending')
        @else
            @include('evorxa::partials.notice')
        @endif

        <div class="evx-toasts" data-evx="toasts" aria-live="polite"></div>
    </div>
    <script type="application/json" id="evx-boot">{!! $evx['bootJson'] !!}</script>
    <script src="{{ $evx['assets'] }}evorxa.js?v={{ $evx['v'] }}"></script>
</div>

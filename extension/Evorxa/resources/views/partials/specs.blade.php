@if(($evx['specs'] ?? null))
<div class="evx-specs">
    @foreach($evx['specs'] ?? [] as $spec)
        <div class="evx-spec">
            <span class="evx-spec-icon" aria-hidden="true"><i class="fas fa-{{ ($spec['icon'] ?? '') }}"></i></span>
            <div>
                <div class="evx-spec-label">{{ ($spec['label'] ?? '') }}</div>
                <div class="evx-spec-value">{{ ($spec['value'] ?? '') }}</div>
            </div>
        </div>
    @endforeach
</div>
@endif

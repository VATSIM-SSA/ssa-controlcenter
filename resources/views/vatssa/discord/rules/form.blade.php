@extends('layouts.app')

@section('title', $rule->id ? 'Edit rule' : 'New rule')

@section('title-flex')
    <div class="d-flex align-items-center"><a href="{{ route('vatssa.discord.rules.index') }}" class="btn btn-icon btn-light d-inline-flex align-items-center">All rules</a></div>
@endsection

@section('content')
@include('vatssa.discord._style')

<form method="post" id="ruleForm" action="{{ $rule->id ? route('vatssa.discord.rules.update', $rule->id) : route('vatssa.discord.rules.store') }}">
    @csrf
    @if($rule->id) @method('PATCH') @endif
    <input type="hidden" name="enabled" value="{{ $rule->enabled ? 1 : 0 }}">

    <div class="row">
        <div class="col-lg-8">
            <div class="card shadow mb-3">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label fw-bold" for="name">Name</label>
                            <input id="name" name="name" maxlength="80" required class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $rule->name) }}" placeholder="ATC TWR: home">
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-7">
                            <label class="form-label fw-bold" for="description">Description <span class="fw-normal text-muted">(optional)</span></label>
                            <input id="description" name="description" maxlength="255" class="form-control" value="{{ old('description', $rule->description) }}">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow mb-3">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <span class="fw-bold">Given when all of these are true</span>
                    <button type="button" class="btn btn-sm btn-icon btn-light" id="addCondition"><i class="fas fa-plus"></i> Add condition</button>
                </div>
                <div class="card-body" id="conditions"></div>
                @error('conditions')<div class="card-footer text-danger">{{ $message }}</div>@enderror
            </div>

            <div class="d-flex flex-wrap align-items-center gap-2 mb-4">
                <button class="btn btn-success">Save rule</button>
                <a href="{{ route('vatssa.discord.rules.index') }}" class="btn btn-icon btn-light d-inline-flex align-items-center">Cancel</a>
                @if($rule->id)
                    <button type="button" class="btn btn-icon btn-outline-danger ms-auto" id="deleteButton"><i class="fas fa-trash"></i> Delete rule</button>
                @endif
            </div>
        </div>

        <div class="col-lg-4">
            <div class="vd-sticky">
                <div class="card shadow mb-3">
                    <div class="card-header py-3 fw-bold">Gives these Discord roles</div>
                    <div class="card-body">
                        @if($guildRoles->isEmpty() && ! $rule->roles)
                            <p class="text-muted mb-0">The bot has not reported the server's roles yet.</p>
                        @else
                            <input type="search" id="roleSearch" class="form-control form-control-sm mb-2" placeholder="Search roles" aria-label="Search roles">
                            <div class="vd-role-list" id="roleList">
                                @php($selected = array_map('strval', old('roles', $rule->roles)))
                                @foreach($guildRoles as $role)
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="roles[]" id="role{{ $role->discord_role_id }}" value="{{ $role->discord_role_id }}" @checked(in_array((string) $role->discord_role_id, $selected, true))>
                                        <label class="form-check-label" for="role{{ $role->discord_role_id }}">{{ $role->name }}</label>
                                    </div>
                                @endforeach
                                @foreach(array_diff($rule->roles, $guildRoles->pluck('discord_role_id')->map(fn ($id) => (string) $id)->all()) as $missing)
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="roles[]" id="role{{ $missing }}" value="{{ $missing }}" @checked(in_array($missing, $selected, true))>
                                        <label class="form-check-label" for="role{{ $missing }}">{{ $missing }} <span class="text-muted">(not seen on the server)</span></label>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        @error('roles')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="card shadow mb-3">
                    <div class="card-header py-3 fw-bold">What this changes</div>
                    <div class="card-body" id="previewBody"><span class="text-muted">Pick a condition and a role.</span></div>
                </div>
            </div>
        </div>
    </div>
</form>

@if($rule->id)
    <div class="modal fade" id="deleteRule" tabindex="-1" aria-labelledby="deleteRuleLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form method="post" class="modal-content" action="{{ route('vatssa.discord.rules.destroy', $rule->id) }}">@csrf @method('DELETE')
                <div class="modal-header"><h5 class="modal-title" id="deleteRuleLabel">Delete {{ $rule->name }}?</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body" id="deleteBody">Checking what this affects…</div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-icon btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-danger">Delete rule</button>
                </div>
            </form>
        </div>
    </div>
@endif

<template id="conditionTemplate">
    <div class="vd-rule-row">
        <div class="vd-and">AND</div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <select class="form-select form-select-sm w-auto" data-name="negated" aria-label="If or if not">
                <option value="0">If</option><option value="1">If not</option>
            </select>
            <select class="form-select form-select-sm w-auto" data-name="type" aria-label="Condition">
                @foreach($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>

            <select class="form-select form-select-sm w-auto" data-name="division" data-for="division" aria-label="Division">
                <option value="home">Home member</option><option value="visitor">Visitor</option>
            </select>

            <select class="form-select form-select-sm w-auto" data-name="rating_mode" data-for="rating" aria-label="How to compare">
                <option value="in">is one of</option><option value="min">at least</option><option value="max">at most</option>
            </select>
            <select class="form-select form-select-sm w-auto" data-name="rating_value" data-for="rating" data-mode="minmax" aria-label="Rating">
                @foreach($ratings as $r)<option value="{{ $r['value'] }}">{{ $r['name'] }}</option>@endforeach
            </select>

            <select class="form-select form-select-sm w-auto" data-name="role" data-for="cc_role" aria-label="CC role">
                @foreach($ccRoles as $role)<option value="{{ $role }}">{{ $role }}</option>@endforeach
            </select>

            <select class="form-select form-select-sm w-auto" data-name="endorsement" data-for="endorsement" aria-label="Endorsement">
                @foreach($endorsements as $e)<option value="{{ $e }}">{{ ucfirst(strtolower($e)) }}</option>@endforeach
            </select>
            <select class="form-select form-select-sm w-auto" data-name="compare" data-for="endorsement" aria-label="Compare rating">
                <option value="any">with rating</option><option value="highest">highest is</option><option value="at_least">highest at least</option>
            </select>
            <select class="form-select form-select-sm w-auto" data-name="endorsement_rating" data-for="endorsement" aria-label="Endorsement rating">
                <option value="">any</option>
                @foreach($ratingNames as $name)<option value="{{ $name }}">{{ $name }}</option>@endforeach
            </select>

            <select class="form-select form-select-sm w-auto" data-name="group" data-for="group" aria-label="Group">
                @foreach($groups as $group)<option value="{{ $group->id }}">{{ $group->name }}</option>@endforeach
            </select>

            <select class="form-select form-select-sm w-auto" data-name="area" data-for="cc_role roster training" aria-label="Area">
                <option value="any">any area</option>
                <option value="global" data-global>global</option>
                @foreach($areas as $area)<option value="{{ $area->id }}">{{ $area->name }}</option>@endforeach
            </select>

            <button type="button" class="btn btn-sm btn-icon btn-light ms-auto remove" aria-label="Remove condition"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="vd-rating-picker d-flex flex-wrap gap-1 mt-2" data-for="rating" data-mode="in" role="group" aria-label="Ratings">
            @foreach($ratings as $r)
                <input type="checkbox" class="btn-check" data-name="ratings" value="{{ $r['value'] }}" autocomplete="off">
                <label class="btn btn-sm btn-outline-secondary">{{ $r['name'] }}</label>
            @endforeach
        </div>
    </div>
</template>
@endsection

@section('js')
<script>
(function () {
    const box = document.getElementById('conditions');
    const template = document.getElementById('conditionTemplate');
    const form = document.getElementById('ruleForm');
    const previewBody = document.getElementById('previewBody');
    const ruleId = @js($rule->id);
    const previewUrl = @js(route('vatssa.discord.rules.preview'));
    let next = 0, timer = null, latest = 0;

    function setEnabled(el, on) {
        el.classList.toggle('d-none', !on);
        [el, ...el.querySelectorAll('input')].forEach(field => {
            if (field.matches('input, select')) field.disabled = !on;
        });
    }

    function refresh(row) {
        const type = row.querySelector('[data-name=type]').value;
        const mode = row.querySelector('[data-name=rating_mode]').value;
        row.querySelectorAll('[data-for]').forEach(el => {
            let show = el.dataset.for.split(' ').includes(type);
            if (show && el.dataset.mode === 'in') show = mode === 'in';
            if (show && el.dataset.mode === 'minmax') show = mode !== 'in';
            setEnabled(el, show);
        });
        const global = row.querySelector('[data-global]');
        global.hidden = global.disabled = type !== 'cc_role';
        const area = row.querySelector('[data-name=area]');
        if (global.disabled && area.value === 'global') area.value = 'any';
    }

    function markFirst() {
        box.querySelectorAll('.vd-rule-row').forEach((row, i) => row.querySelector('.vd-and').classList.toggle('d-none', i === 0));
    }

    function add(condition) {
        const row = template.content.firstElementChild.cloneNode(true);
        const index = next++;
        row.querySelectorAll('[data-name]').forEach(el => {
            el.name = 'conditions[' + index + '][' + el.dataset.name + ']' + (el.type === 'checkbox' ? '[]' : '');
        });
        row.querySelectorAll('.vd-rating-picker input').forEach(input => {
            input.id = 'rating' + index + '_' + input.value;
            input.nextElementSibling.htmlFor = input.id;
        });
        const set = (name, value) => { if (value !== undefined && value !== null) row.querySelector('[data-name=' + name + ']').value = String(value); };
        if (condition) {
            const p = condition.params || {};
            set('negated', condition.negated ? 1 : 0);
            set('type', condition.type);
            if (condition.type === 'division') set('division', p.value);
            if (condition.type === 'rating') {
                set('rating_mode', p.mode);
                if (p.mode === 'in') {
                    const wanted = (p.values || []).map(String);
                    row.querySelectorAll('[data-name=ratings]').forEach(input => { input.checked = wanted.includes(input.value); });
                } else { set('rating_value', p.value); }
            }
            set('role', p.role);
            if (condition.type === 'endorsement') { set('endorsement', p.type); set('compare', p.compare); set('endorsement_rating', p.rating || ''); }
            set('group', p.group);
            set('area', p.area);
        }
        row.addEventListener('change', () => { refresh(row); schedule(); });
        row.querySelector('.remove').addEventListener('click', () => { row.remove(); markFirst(); schedule(); });
        box.appendChild(row);
        refresh(row);
        markFirst();
    }

    function line(parent, text, muted) {
        const p = document.createElement('div');
        p.className = muted ? 'small text-muted mt-2' : 'mb-1';
        p.textContent = text;
        parent.appendChild(p);
    }

    async function fetchPreview(extra) {
        const data = new FormData(form);
        data.delete('_method');
        data.delete('dropped');
        if (ruleId) data.set('rule_id', ruleId);
        Object.entries(extra || {}).forEach(([k, v]) => data.set(k, v));
        const response = await fetch(previewUrl, { method: 'POST', body: data, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        return { ok: response.ok, body: await response.json().catch(() => ({})) };
    }

    function droppedChoice(parent, dropped) {
        if (!dropped.length) return;
        const wrap = document.createElement('div');
        wrap.className = 'mt-3 pt-3 border-top';
        const label = document.createElement('label');
        label.className = 'form-label';
        label.htmlFor = 'dropped' + parent.id;
        label.textContent = 'No rule gives ' + dropped.map(r => r.role + ' (' + r.members + ')').join(', ') + ' any more. For members who have it:';
        const select = document.createElement('select');
        select.className = 'form-select form-select-sm';
        select.id = 'dropped' + parent.id;
        select.name = 'dropped';
        [['keep', 'Keep it on them'], ['remove', 'Remove it']].forEach(([value, text]) => select.add(new Option(text, value)));
        wrap.append(label, select);
        parent.appendChild(wrap);
    }

    function renderChanges(parent, body) {
        const plural = n => n + (n === 1 ? ' member' : ' members');
        body.adds.forEach(r => line(parent, '+ ' + r.role + ': ' + plural(r.members)));
        body.removes.forEach(r => line(parent, '− ' + r.role + ': ' + plural(r.members)));
        if (!body.adds.length && !body.removes.length) line(parent, 'No role changes.');
        line(parent, 'From ' + body.counted + ' accounts. People with no CC account are not counted.', true);
    }

    async function preview() {
        const ticket = ++latest;
        const hasRole = form.querySelector('[name="roles[]"]:checked');
        if (!hasRole || !document.getElementById('name').value.trim()) {
            previewBody.replaceChildren();
            line(previewBody, 'Add a name and pick a role to see the effect.', true);
            return;
        }
        const { ok, body } = await fetchPreview();
        if (ticket !== latest) return;
        previewBody.replaceChildren();
        if (!ok) { line(previewBody, body.message || 'This rule is not complete yet.', true); return; }
        renderChanges(previewBody, body);
        droppedChoice(previewBody, body.dropped);
    }

    function schedule() {
        clearTimeout(timer);
        timer = setTimeout(preview, 400);
    }

    document.getElementById('addCondition').addEventListener('click', () => { add(); schedule(); });
    form.querySelectorAll('[name="roles[]"], #name').forEach(el => el.addEventListener('change', schedule));

    const roleSearch = document.getElementById('roleSearch');
    if (roleSearch) {
        roleSearch.addEventListener('input', () => {
            const term = roleSearch.value.trim().toLowerCase();
            document.querySelectorAll('#roleList .form-check').forEach(item => {
                item.classList.toggle('d-none', term !== '' && !item.textContent.toLowerCase().includes(term));
            });
        });
        roleSearch.addEventListener('keydown', e => { if (e.key === 'Enter') e.preventDefault(); });
    }

    const deleteButton = document.getElementById('deleteButton');
    if (deleteButton) {
        const modalEl = document.getElementById('deleteRule');
        const deleteBody = document.getElementById('deleteBody');
        deleteButton.addEventListener('click', async () => {
            deleteBody.textContent = 'Checking what this affects…';
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
            const { ok, body } = await fetchPreview({ delete: 1 });
            deleteBody.replaceChildren();
            if (!ok) { line(deleteBody, 'Could not check the effect. The rule can still be deleted.', true); return; }
            renderChanges(deleteBody, body);
            droppedChoice(deleteBody, body.dropped);
        });
    }

    const initial = @js($rule->conditions);
    initial.length ? initial.forEach(add) : add();
    preview();
})();
</script>
@endsection

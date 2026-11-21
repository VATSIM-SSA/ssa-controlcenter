@extends('layouts.app')

@section('title', $rule->id ? 'Edit rule' : 'New rule')

@section('content')
<form method="post" id="ruleForm" action="{{ $rule->id ? route('vatssa.discord.rules.update', $rule->id) : route('vatssa.discord.rules.store') }}">
    @csrf
    @if($rule->id) @method('PATCH') @endif
    <input type="hidden" name="enabled" value="{{ $rule->enabled ? 1 : 0 }}">

    <div class="card shadow mb-3">
        <div class="card-header bg-primary py-3"><h6 class="m-0 fw-bold text-white">{{ $rule->id ? 'Edit rule' : 'New rule' }}</h6></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label" for="name">Name</label>
                    <input id="name" name="name" maxlength="80" required class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $rule->name) }}">
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-7">
                    <label class="form-label" for="description">Description</label>
                    <input id="description" name="description" maxlength="255" class="form-control" value="{{ old('description', $rule->description) }}">
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow mb-3">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <span class="fw-bold">When all of these hold</span>
            <button type="button" class="btn btn-sm btn-icon btn-light" id="addCondition"><i class="fas fa-plus"></i> Add condition</button>
        </div>
        <div class="card-body" id="conditions"></div>
        @error('conditions')<div class="card-footer text-danger">{{ $message }}</div>@enderror
    </div>

    <div class="card shadow mb-3">
        <div class="card-header py-3 fw-bold">Give these Discord roles</div>
        <div class="card-body">
            @if($guildRoles->isEmpty())
                <p class="text-muted mb-2">The bot has not reported the server's roles yet.</p>
            @endif
            <select name="roles[]" multiple size="8" class="form-select @error('roles') is-invalid @enderror" aria-label="Discord roles" required>
                @foreach($guildRoles as $role)
                    <option value="{{ $role->discord_role_id }}" @selected(in_array((string) $role->discord_role_id, $rule->roles, true))>{{ $role->name }}</option>
                @endforeach
                @foreach(array_diff($rule->roles, $guildRoles->pluck('discord_role_id')->map(fn ($id) => (string) $id)->all()) as $missing)
                    <option value="{{ $missing }}" selected>{{ $missing }} (not seen on the server)</option>
                @endforeach
            </select>
            @error('roles')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>

    <div class="card shadow mb-3 d-none" id="previewCard">
        <div class="card-header py-3 fw-bold">What this would change</div>
        <div class="card-body" id="previewBody"></div>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-4">
        <button type="button" class="btn btn-icon btn-light" id="previewButton">Preview changes</button>
        <button class="btn btn-success">Save rule</button>
        <a href="{{ route('vatssa.discord.rules.index') }}" class="btn btn-icon btn-light">Cancel</a>
        @if($rule->id)
            <button type="button" class="btn btn-icon btn-danger ms-auto" id="deleteButton"><i class="fas fa-trash"></i> Delete rule</button>
        @endif
    </div>
</form>

@if($rule->id)
    <form method="post" id="deleteForm" action="{{ route('vatssa.discord.rules.destroy', $rule->id) }}">@csrf @method('DELETE')<input type="hidden" name="dropped" id="deleteDropped" value="keep"></form>
@endif

<template id="conditionTemplate">
    <div class="border rounded p-2 mb-2 condition">
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <select class="form-select form-select-sm w-auto" data-name="negated" aria-label="Is or is not">
                <option value="0">Is</option><option value="1">Is NOT</option>
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
            <select class="form-select form-select-sm w-auto" data-name="ratings" data-for="rating" data-mode="in" multiple size="3" aria-label="Ratings">
                @foreach($ratings as $r)<option value="{{ $r['value'] }}">{{ $r['name'] }}</option>@endforeach
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
                <option value="">any rating</option>
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
    </div>
</template>
@endsection

@section('js')
<script>
(function () {
    const box = document.getElementById('conditions');
    const template = document.getElementById('conditionTemplate');
    const form = document.getElementById('ruleForm');
    const previewCard = document.getElementById('previewCard');
    const previewBody = document.getElementById('previewBody');
    const ruleId = @js($rule->id);
    let next = 0;

    function refresh(row) {
        const type = row.querySelector('[data-name=type]').value;
        const mode = row.querySelector('[data-name=rating_mode]').value;
        row.querySelectorAll('[data-for]').forEach(el => {
            let show = el.dataset.for.split(' ').includes(type);
            if (show && el.dataset.mode === 'in') show = mode === 'in';
            if (show && el.dataset.mode === 'minmax') show = mode !== 'in';
            el.classList.toggle('d-none', !show);
            el.disabled = !show;
        });
        const global = row.querySelector('[data-global]');
        global.hidden = global.disabled = type !== 'cc_role';
    }

    function add(condition) {
        const row = template.content.firstElementChild.cloneNode(true);
        const index = next++;
        row.querySelectorAll('[data-name]').forEach(el => {
            el.name = 'conditions[' + index + '][' + el.dataset.name + ']' + (el.multiple ? '[]' : '');
        });
        const set = (name, value) => { if (value !== undefined && value !== null) row.querySelector('[data-name=' + name + ']').value = String(value); };
        if (condition) {
            const p = condition.params || {};
            set('negated', condition.negated ? 1 : 0);
            set('type', condition.type);
            set('division', p.value === 'home' || p.value === 'visitor' ? p.value : null);
            if (condition.type === 'rating') {
                set('rating_mode', p.mode);
                if (p.mode === 'in') {
                    const wanted = (p.values || []).map(String);
                    row.querySelectorAll('[data-name=ratings] option').forEach(o => { o.selected = wanted.includes(o.value); });
                } else { set('rating_value', p.value); }
            }
            set('role', p.role);
            if (condition.type === 'endorsement') { set('endorsement', p.type); set('compare', p.compare); set('endorsement_rating', p.rating || ''); }
            set('group', p.group);
            set('area', p.area);
        }
        row.addEventListener('change', () => refresh(row));
        row.querySelector('.remove').addEventListener('click', () => row.remove());
        box.appendChild(row);
        refresh(row);
    }

    function line(text) {
        const p = document.createElement('p');
        p.className = 'mb-1';
        p.textContent = text;
        previewBody.appendChild(p);
    }

    async function preview(extra) {
        const data = new FormData(form);
        data.delete('_method');
        if (ruleId) data.set('rule_id', ruleId);
        Object.entries(extra || {}).forEach(([k, v]) => data.set(k, v));
        const response = await fetch(@js(route('vatssa.discord.rules.preview')), { method: 'POST', body: data, headers: { Accept: 'application/json' } });
        const body = await response.json();
        previewCard.classList.remove('d-none');
        previewBody.replaceChildren();
        if (!response.ok) { line(body.message || 'Could not preview this rule.'); return null; }
        body.adds.forEach(r => line('Adds ' + r.role + ' to ' + r.members + (r.members === 1 ? ' member' : ' members')));
        body.removes.forEach(r => line('Removes ' + r.role + ' from ' + r.members + (r.members === 1 ? ' member' : ' members')));
        if (!body.adds.length && !body.removes.length) line('No role changes.');
        const small = document.createElement('small');
        small.className = 'text-muted';
        small.textContent = 'Based on ' + body.counted + ' accounts. People with no CC account are not counted.';
        previewBody.appendChild(small);
        return body;
    }

    function droppedChoice(body) {
        previewBody.querySelector('#droppedBox')?.remove();
        if (!body || !body.dropped.length) return;
        const wrap = document.createElement('div');
        wrap.id = 'droppedBox';
        wrap.className = 'mt-3';
        const names = body.dropped.map(r => r.role + ' (' + r.members + ')').join(', ');
        const label = document.createElement('label');
        label.className = 'form-label';
        label.htmlFor = 'dropped';
        label.textContent = 'No rule gives these any more: ' + names + '. What should happen to members who have them?';
        const select = document.createElement('select');
        select.className = 'form-select form-select-sm w-auto';
        select.id = 'dropped';
        select.name = 'dropped';
        [['keep', 'Keep it on them'], ['remove', 'Remove it']].forEach(([value, text]) => select.add(new Option(text, value)));
        wrap.append(label, select);
        previewBody.appendChild(wrap);
    }

    document.getElementById('addCondition').addEventListener('click', () => add());
    document.getElementById('previewButton').addEventListener('click', async () => droppedChoice(await preview()));

    const deleteButton = document.getElementById('deleteButton');
    if (deleteButton) {
        deleteButton.addEventListener('click', async () => {
            const body = await preview({ delete: 1 });
            if (!body) return;
            const names = body.dropped.map(r => r.role + ' (' + r.members + ')').join(', ');
            let remove = false;
            if (names) remove = confirm('No rule will give: ' + names + '.\n\nOK removes these roles from those members. Cancel keeps them.');
            if (!confirm('Delete this rule?')) return;
            document.getElementById('deleteDropped').value = remove ? 'remove' : 'keep';
            document.getElementById('deleteForm').submit();
        });
    }

    const initial = @js($rule->conditions);
    initial.length ? initial.forEach(add) : add();
})();
</script>
@endsection

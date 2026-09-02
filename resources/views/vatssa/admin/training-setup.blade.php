{{--
    VATSSA: the three lists that decide what training looks like here.

    Ratings and endorsements, training types, and request desks. All three used
    to be unreachable from the interface -- one was a table with no page, one a
    static array in an upstream controller, one a class constant -- so changing
    the shape of the training programme needed somebody who could write PHP and
    deploy.

    ## Why one page and not three

    They are the same question asked three ways: "what kinds of thing exist
    here". Three pages would mean three places to look for the setting somebody
    half-remembers, and this is not a page anybody opens every day.

    ## Accordions, not a wall

    Each section is collapsed with its own count in the header. The alternative
    is nine screens of forms, where the one you came to change is the one you
    scroll past. Same pattern as the pipeline templates page.
--}}
@extends('layouts.app')

@section('title', 'Training setup')

@section('content')

<div class="row">
    <div class="col-12">
        <div class="alert alert-info" role="alert">
            <i class="fas fa-circle-info"></i>
            Nothing here is ever deleted. A training type or a desk you retire disappears
            from the forms and stays on the records that already used it &mdash; a closed
            training whose kind went blank is a history that lies.
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">

        <div class="accordion" id="setupAccordion">

            {{-- ---------------------------------------------------------- --}}
            {{-- Ratings and endorsements                                    --}}
            {{-- ---------------------------------------------------------- --}}
            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" type="button"
                            data-bs-toggle="collapse" data-bs-target="#ratingsPane">
                        Ratings and endorsements
                        <span class="badge bg-light text-dark ms-2">{{ $ratings->count() }}</span>
                    </button>
                </h2>
                <div id="ratingsPane" class="accordion-collapse collapse" data-bs-parent="#setupAccordion">
                    <div class="accordion-body">

                        <p class="text-muted">
                            A <strong>rating</strong> is one VATSIM issues and carries a VATSIM
                            rating number. An <strong>endorsement</strong> has none &mdash; that
                            is the only difference, and it is how the major-airport and oceanic
                            entries sit in the same list as S1.
                        </p>

                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Name</th>
                                        <th>Description</th>
                                        <th>Kind</th>
                                        <th class="text-end">Save</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($ratings as $rating)
                                        <tr>
                                            <form method="POST" action="{{ route('vatssa.admin.setup.ratings.update', $rating) }}">
                                                @csrf
                                                @method('PATCH')
                                                <td>
                                                    <input type="text" class="form-control form-control-sm"
                                                           name="name" value="{{ $rating->name }}"
                                                           required maxlength="50">
                                                </td>
                                                <td>
                                                    <input type="text" class="form-control form-control-sm"
                                                           name="description" value="{{ $rating->description }}"
                                                           required maxlength="100">
                                                </td>
                                                <td>
                                                    @if($rating->vatsim_rating)
                                                        <span class="badge bg-primary">Rating</span>
                                                        <small class="text-muted">{{ $rating->vatsim_rating->name }}</small>
                                                    @else
                                                        <span class="badge bg-secondary">Endorsement</span>
                                                    @endif
                                                </td>
                                                <td class="text-end">
                                                    <button class="btn btn-sm btn-outline-primary" type="submit">Save</button>
                                                </td>
                                            </form>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <hr>

                        <h6 class="fw-bold">Add one</h6>
                        <form method="POST" action="{{ route('vatssa.admin.setup.ratings.store') }}">
                            @csrf
                            <div class="row g-2 align-items-end">
                                <div class="col-md-3">
                                    <label class="form-label" for="r-name">Name</label>
                                    <input type="text" class="form-control form-control-sm" id="r-name"
                                           name="name" required maxlength="50" placeholder="MAE FAOR TWR">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="r-desc">Description</label>
                                    <input type="text" class="form-control form-control-sm" id="r-desc"
                                           name="description" required maxlength="100">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label" for="r-kind">Kind</label>
                                    <select class="form-select form-select-sm" id="r-kind" name="kind" required>
                                        <option value="endorsement">Endorsement</option>
                                        <option value="rating">Rating</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label" for="r-vatsim">VATSIM rating no.</label>
                                    <input type="number" class="form-control form-control-sm" id="r-vatsim"
                                           name="vatsim_rating" min="1" max="12"
                                           placeholder="rating only">
                                </div>
                                <div class="col-md-1">
                                    <button class="btn btn-sm btn-primary w-100" type="submit">Add</button>
                                </div>
                            </div>
                        </form>

                    </div>
                </div>
            </div>

            {{-- ---------------------------------------------------------- --}}
            {{-- Training types                                              --}}
            {{-- ---------------------------------------------------------- --}}
            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" type="button"
                            data-bs-toggle="collapse" data-bs-target="#typesPane">
                        Training types
                        <span class="badge bg-light text-dark ms-2">{{ $types->count() }}</span>
                    </button>
                </h2>
                <div id="typesPane" class="accordion-collapse collapse" data-bs-parent="#setupAccordion">
                    <div class="accordion-body">

                        <p class="text-muted">
                            What kind of training a request is: standard, refresh, transfer,
                            an endorsement course, whatever the division runs. The icon is a
                            Font Awesome class &mdash; leave it blank for a plain dot.
                        </p>

                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:3rem">#</th>
                                        <th>Name</th>
                                        <th>Icon</th>
                                        <th style="width:6rem">Order</th>
                                        <th style="width:6rem">Offered</th>
                                        <th class="text-end">Save</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($types as $type)
                                        <tr>
                                            <form method="POST" action="{{ route('vatssa.admin.setup.types.update', $type) }}">
                                                @csrf
                                                @method('PATCH')
                                                <td class="text-muted">{{ $type->id }}</td>
                                                <td>
                                                    <input type="text" class="form-control form-control-sm"
                                                           name="name" value="{{ $type->name }}" required maxlength="60">
                                                </td>
                                                <td>
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-text"><i class="{{ $type->icon }}"></i></span>
                                                        <input type="text" class="form-control form-control-sm"
                                                               name="icon" value="{{ $type->icon }}" maxlength="60">
                                                    </div>
                                                </td>
                                                <td>
                                                    <input type="number" class="form-control form-control-sm"
                                                           name="sort_order" value="{{ $type->sort_order }}" min="0" max="999">
                                                </td>
                                                <td>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox"
                                                               name="active" value="1" @checked($type->active)>
                                                    </div>
                                                </td>
                                                <td class="text-end">
                                                    <button class="btn btn-sm btn-outline-primary" type="submit">Save</button>
                                                </td>
                                            </form>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <hr>

                        <h6 class="fw-bold">Add one</h6>
                        <p class="text-muted small">
                            It will be number {{ $nextTypeId }}. That number is what gets stored
                            on every training of this kind, so it cannot be changed afterwards.
                        </p>
                        <form method="POST" action="{{ route('vatssa.admin.setup.types.store') }}">
                            @csrf
                            <div class="row g-2 align-items-end">
                                <div class="col-md-4">
                                    <label class="form-label" for="t-name">Name</label>
                                    <input type="text" class="form-control form-control-sm" id="t-name"
                                           name="name" required maxlength="60" placeholder="Endorsement training">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="t-icon">Icon</label>
                                    <input type="text" class="form-control form-control-sm" id="t-icon"
                                           name="icon" maxlength="60" placeholder="fas fa-certificate">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="t-desc">Description</label>
                                    <input type="text" class="form-control form-control-sm" id="t-desc"
                                           name="description" maxlength="500">
                                </div>
                                <div class="col-md-1">
                                    <label class="form-label" for="t-order">Order</label>
                                    <input type="number" class="form-control form-control-sm" id="t-order"
                                           name="sort_order" min="0" max="999" value="99">
                                </div>
                                <div class="col-md-1">
                                    <button class="btn btn-sm btn-primary w-100" type="submit">Add</button>
                                </div>
                            </div>
                        </form>

                    </div>
                </div>
            </div>

            {{-- ---------------------------------------------------------- --}}
            {{-- Request desks                                               --}}
            {{-- ---------------------------------------------------------- --}}
            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" type="button"
                            data-bs-toggle="collapse" data-bs-target="#desksPane">
                        Request desks
                        <span class="badge bg-light text-dark ms-2">{{ $desks->count() }}</span>
                    </button>
                </h2>
                <div id="desksPane" class="accordion-collapse collapse" data-bs-parent="#setupAccordion">
                    <div class="accordion-body">

                        <p class="text-muted">
                            Where a request goes. <strong>Per rating</strong> means the desk is
                            staffed separately for each rating &mdash; an S2 request reaches the
                            S2 coordinator. Who actually sits at each desk is set under
                            <a href="{{ route('vatssa.admin.routing') }}">Request routing</a>.
                        </p>

                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Key</th>
                                        <th>Label</th>
                                        <th>Hint shown to the member</th>
                                        <th style="width:6rem">Per rating</th>
                                        <th style="width:6rem">Order</th>
                                        <th style="width:6rem">Offered</th>
                                        <th class="text-end">Save</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($desks as $desk)
                                        <tr>
                                            <form method="POST" action="{{ route('vatssa.admin.setup.desks.update', $desk) }}">
                                                @csrf
                                                @method('PATCH')
                                                <td><code class="small">{{ $desk->key }}</code></td>
                                                <td>
                                                    <input type="text" class="form-control form-control-sm"
                                                           name="label" value="{{ $desk->label }}" required maxlength="80">
                                                </td>
                                                <td>
                                                    <input type="text" class="form-control form-control-sm"
                                                           name="hint" value="{{ $desk->hint }}" maxlength="255">
                                                </td>
                                                <td>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox"
                                                               name="per_rating" value="1" @checked($desk->per_rating)>
                                                    </div>
                                                </td>
                                                <td>
                                                    <input type="number" class="form-control form-control-sm"
                                                           name="sort_order" value="{{ $desk->sort_order }}" min="0" max="999">
                                                </td>
                                                <td>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox"
                                                               name="active" value="1" @checked($desk->active)>
                                                    </div>
                                                </td>
                                                <td class="text-end">
                                                    <button class="btn btn-sm btn-outline-primary" type="submit">Save</button>
                                                </td>
                                            </form>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <hr>

                        <h6 class="fw-bold">Add one</h6>
                        <p class="text-muted small">
                            The key is stored on every request routed here and cannot be changed
                            afterwards. Lower case, digits and hyphens.
                        </p>
                        <form method="POST" action="{{ route('vatssa.admin.setup.desks.store') }}">
                            @csrf
                            <div class="row g-2 align-items-end">
                                <div class="col-md-2">
                                    <label class="form-label" for="d-key">Key</label>
                                    <input type="text" class="form-control form-control-sm" id="d-key"
                                           name="key" required maxlength="40"
                                           pattern="[a-z0-9\-]+" placeholder="events">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="d-label">Label</label>
                                    <input type="text" class="form-control form-control-sm" id="d-label"
                                           name="label" required maxlength="80" placeholder="Events team">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="d-hint">Hint</label>
                                    <input type="text" class="form-control form-control-sm" id="d-hint"
                                           name="hint" maxlength="255">
                                </div>
                                <div class="col-md-2">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" id="d-per"
                                               name="per_rating" value="1">
                                        <label class="form-check-label" for="d-per">Per rating</label>
                                    </div>
                                </div>
                                <div class="col-md-1">
                                    <button class="btn btn-sm btn-primary w-100" type="submit">Add</button>
                                </div>
                            </div>
                        </form>

                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

@endsection

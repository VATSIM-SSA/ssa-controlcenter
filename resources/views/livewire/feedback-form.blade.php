<div>
    <form action="{{ route('feedback.store') }}" method="POST" x-data="{ sending: false }" x-on:submit="sending = true">
        @csrf
        @if($areaId)
            <input type="hidden" name="area" value="{{ $areaId }}">
        @endif

        {{-- The controller --}}
        <h6 class="fw-bold text-uppercase small text-muted mb-3">The controller</h6>

        <div class="mb-3">
            <label class="form-label fw-bold" for="feedback-controller-search">Controller <span class="text-danger" aria-hidden="true">*</span></label>

            @if($controller)
                <input type="hidden" name="controller" value="{{ $controller }}">
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-user"></i></span>
                    <input type="text" class="form-control" value="{{ $controllerLabel }}" readonly aria-label="Selected controller">
                    <button type="button" class="btn btn-outline-secondary" wire:click="clearController" aria-label="Choose another controller">
                        <i class="fas fa-xmark"></i>
                    </button>
                </div>
            @else
                <div class="position-relative" x-data="{ open: false }" x-on:click.outside="open = false">
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-magnifying-glass"></i></span>
                        <input
                            id="feedback-controller-search"
                            type="text"
                            class="form-control @error('controller') is-invalid @enderror"
                            placeholder="Name or CID"
                            autocomplete="off"
                            role="combobox"
                            x-bind:aria-expanded="open"
                            wire:model.live.debounce.250ms="search"
                            x-on:focus="open = true"
                            x-on:input="open = true"
                            x-on:keydown.escape="open = false"
                        >
                    </div>

                    <div x-cloak x-show="open" class="list-group position-absolute w-100 shadow mt-1" style="z-index: 1050; max-height: 18rem; overflow-y: auto;">
                        @if($this->results['online']->isNotEmpty())
                            <span class="list-group-item small fw-bold text-uppercase text-muted py-1">Online now</span>
                            @foreach($this->results['online'] as $result)
                                <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                                    wire:click="selectController({{ $result['id'] }})" x-on:click="open = false">
                                    <span>{{ $result['name'] }} <small class="text-muted">{{ $result['id'] }}</small></span>
                                    <span class="badge bg-success">{{ $result['detail'] }}</span>
                                </button>
                            @endforeach
                        @endif

                        @if($this->results['others']->isNotEmpty())
                            @if($this->results['online']->isNotEmpty())
                                <span class="list-group-item small fw-bold text-uppercase text-muted py-1">Controllers</span>
                            @endif
                            @foreach($this->results['others'] as $result)
                                <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                                    wire:click="selectController({{ $result['id'] }})" x-on:click="open = false">
                                    <span>{{ $result['name'] }}</span>
                                    <small class="text-muted">{{ $result['detail'] }}</small>
                                </button>
                            @endforeach
                        @endif

                        @if($this->results['online']->isEmpty() && $this->results['others']->isEmpty())
                            <span class="list-group-item small text-muted">
                                {{ trim($search) === '' ? 'Nobody is controlling here right now. Type a name or CID to search.' : 'No controller found on our rosters.' }}
                            </span>
                        @endif
                    </div>
                </div>
            @endif

            @error('controller')
                <span class="text-danger small d-block">{{ $message }}</span>
            @enderror
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <label class="form-label fw-bold" for="feedback-session">Their session <small class="fw-normal text-muted">(optional)</small></label>
                @if($controller && count($sessions) > 0)
                    <select id="feedback-session" class="form-select" wire:model.live="session">
                        <option value="">Select a session</option>
                        @foreach($sessions as $index => $option)
                            <option value="{{ $index }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                    @if($selectedSession)
                        <input type="hidden" name="session_callsign" value="{{ $selectedSession['callsign'] }}">
                        <input type="hidden" name="session_started_at" value="{{ $selectedSession['start'] }}">
                        <input type="hidden" name="session_ended_at" value="{{ $selectedSession['end'] }}">
                    @endif
                @else
                    <select id="feedback-session" class="form-select" disabled>
                        <option>{{ $controller ? 'No sessions in the last ' . \App\Livewire\FeedbackForm::SESSION_DAYS . ' days' : 'Choose a controller first' }}</option>
                    </select>
                @endif
            </div>

            <div class="col-md-6">
                <label class="form-label fw-bold" for="feedback-position">Their position <small class="fw-normal text-muted">(optional)</small></label>
                <input id="feedback-position" type="text" class="form-control @error('position') is-invalid @enderror" name="position" list="feedback-positions"
                    placeholder="e.g. {{ $this->positions->first()?->callsign ?? 'ABCD_APP' }}" wire:model.blur="position" autocomplete="off">
                <datalist id="feedback-positions">
                    @foreach($this->positions as $positionOption)
                        <option value="{{ $positionOption->callsign }}">{{ $positionOption->name }}</option>
                    @endforeach
                </datalist>
                @error('position')
                    <span class="text-danger small d-block">{{ $message }}</span>
                @enderror
            </div>
        </div>

        {{-- The pilot's flight (see PilotFlightHistoryService) --}}
        <div wire:ignore
            x-data="{
                callsign: @js(old('pilot_callsign', '')),
                date: @js(old('event_date', '')),
                departure: @js(old('departure_airport', '')),
                arrival: @js(old('arrival_airport', '')),
                pick(event) {
                    const flight = event.target.selectedOptions[0];
                    if (! flight.value) { return; }
                    this.callsign = flight.value;
                    this.date = flight.dataset.date;
                    this.departure = flight.dataset.departure;
                    this.arrival = flight.dataset.arrival;
                },
            }">
            <h6 class="fw-bold text-uppercase small text-muted mb-3">Your flight <span class="fw-normal text-lowercase">(optional)</span></h6>

            @if(! empty($flights))
                <div class="mb-3">
                    <label class="form-label fw-bold" for="recentFlight">Your recent flights</label>
                    <select id="recentFlight" class="form-select" x-on:change="pick($event)">
                        <option value="">Pick a flight to fill in the fields below</option>
                        @foreach($flights as $flight)
                            <option value="{{ $flight['callsign'] }}" data-date="{{ $flight['date'] }}" data-departure="{{ $flight['departure'] }}" data-arrival="{{ $flight['arrival'] }}">
                                {{ $flight['callsign'] }}@if($flight['departure'] || $flight['arrival']) &middot; {{ $flight['departure'] ?? '?' }} &rarr; {{ $flight['arrival'] ?? '?' }}@endif &middot; {{ \Carbon\Carbon::parse($flight['date'])->toEuropeanDate() }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold" for="pilotCallsign">Pilot callsign</label>
                    <input id="pilotCallsign" class="form-control" type="text" name="pilot_callsign" maxlength="20" placeholder="e.g. ABC123" autocomplete="off"
                        value="{{ old('pilot_callsign') }}" x-model="callsign" x-on:input="callsign = callsign.toUpperCase()">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold" for="eventDate">Date of event, UTC</label>
                    <input id="eventDate" class="form-control" type="date" name="event_date" max="{{ now()->toDateString() }}"
                        value="{{ old('event_date') }}" x-model="date">
                </div>
            </div>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label fw-bold" for="departureAirport">Departure airport, ICAO</label>
                    <input id="departureAirport" class="form-control" type="text" name="departure_airport" maxlength="4" placeholder="e.g. ESSA" autocomplete="off"
                        value="{{ old('departure_airport') }}" x-model="departure" x-on:input="departure = departure.toUpperCase()">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold" for="arrivalAirport">Arrival airport, ICAO</label>
                    <input id="arrivalAirport" class="form-control" type="text" name="arrival_airport" maxlength="4" placeholder="e.g. EKCH" autocomplete="off"
                        value="{{ old('arrival_airport') }}" x-model="arrival" x-on:input="arrival = arrival.toUpperCase()">
                </div>
            </div>
        </div>
        @foreach(['pilot_callsign', 'event_date', 'departure_airport', 'arrival_airport'] as $flightField)
            @error($flightField)
                <span class="text-danger small d-block mb-2">{{ $message }}</span>
            @enderror
        @endforeach

        {{-- The feedback --}}
        <div class="mb-3" wire:ignore>
            <label class="form-label fw-bold" for="feedback-text">Your feedback <span class="text-danger" aria-hidden="true">*</span> <small class="fw-normal text-muted">(English only)</small></label>
            <textarea id="feedback-text" class="form-control" name="feedback" rows="6" maxlength="16000"
                placeholder="What happened? What did the controller do well, and is there anything they could improve?">{{ old('feedback') }}</textarea>
        </div>
        @error('feedback')
            <span class="text-danger small d-block mb-2">{{ $message }}</span>
        @enderror

        <p class="small text-muted mb-0">Your feedback goes to our staff, not straight to the controller. Staff decide what to pass on, and never share your name.</p>

        <hr class="my-4">

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <button type="submit" class="btn btn-success" x-bind:disabled="sending">
                Submit feedback
                <span class="spinner-border spinner-border-sm ms-1" role="status" x-cloak x-show="sending"></span>
            </button>
            <small class="text-muted">Signed in as {{ auth()->user()->name }} ({{ auth()->id() }})</small>
        </div>
    </form>
</div>

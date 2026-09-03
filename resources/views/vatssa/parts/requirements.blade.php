{{--
    VATSSA: the requirement list, tick or cross, in one component.

    ## Why this exists

    Every rule in it already existed, scattered across TrainingPolicy::apply(),
    PlatformRequirement, UserPlatform and TheoryAttempt -- all written to
    REFUSE. The policy returns the first denial it reaches, as one sentence, in
    a pill. So a member learned one reason at a time, in whatever order the
    policy happened to check, and could not see what the requirements WERE until
    they had been turned away by them.

    Same rules, asked instead of only enforced. Rendered identically on the
    dashboard and the application page, so the two cannot drift.

    ## Blocking versus shown

    A cross in red stops the application; a cross in amber does not. A rule that
    blocks silently is a rule nobody can appeal, so most of these are amber and
    the reader is told what to do rather than refused.

    Expects: $requirements   Collection<App\Services\Vatssa\Requirement>
    Optional: $heading       a line above the list
--}}
@if($requirements->isNotEmpty())
    @isset($heading)
        <p class="fw-bold fs-sm text-uppercase text-gray-600 mb-2">{{ $heading }}</p>
    @endisset

    <ul class="list-unstyled mb-0">
        @foreach($requirements as $requirement)
            <li class="mb-2 d-flex">
                <i class="{{ $requirement->icon() }} mt-1 me-2"></i>
                <span>
                    <span class="{{ $requirement->met ? '' : 'fw-bold' }}">{{ $requirement->label }}</span>
                    @if($requirement->detail)
                        <span class="d-block text-muted fs-sm">{{ $requirement->detail }}</span>
                    @endif
                </span>
            </li>
        @endforeach
    </ul>
@endif

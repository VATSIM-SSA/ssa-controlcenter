<?php

namespace App\Livewire;

use App\Contracts\ComboboxOptionProvider;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Modelable;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * A generic, reusable free-text-plus-suggestions combobox.
 *
 * It stays lazy: no options are queried or rendered until the user has typed
 * at least {@see $minChars} characters, and the option set is delegated to a
 * pluggable {@see ComboboxOptionProvider} (which applies its own scope and
 * limit). The typed/selected string is the value, bound to the parent via
 * wire:model.
 */
class Combobox extends Component
{
    #[Modelable]
    public string $value = '';

    /** FQCN of a ComboboxOptionProvider. Locked so a tampered request cannot swap it. */
    #[Locked]
    /*
    | VATSSA: #[Locked].
    |
    | `options()` does `app($this->provider)` -- it resolves a class name out of
    | the container. Livewire rehydrates public properties from the request
    | payload, so without this the class name comes from the browser.
    |
    | The `instanceof ComboboxOptionProvider` check below stops the RESULT of a
    | wrong class being returned. It does not stop the class being built:
    | `app()` instantiates before anything is checked, so any constructor the
    | container can satisfy runs first. That is a real primitive to hand a
    | member, for no benefit -- the provider is set once by the blade that
    | mounts this and is never something a person chooses.
    |
    | `$context` is locked for the same reason: it is passed straight to
    | `options()`, and every provider treats it as trusted (`['area' => 5]`),
    | which is exactly what a filter that decides what rows come back must not be.
    */
    #[Locked]
    public string $provider;

    /**
     * Extra, serializable context passed to the provider (e.g. ['area' => 5]).
     * Nullable because it is #[Reactive]: a parent that binds no context pushes
     * null on re-render, which must not crash the component.
     *
     * @var array<string, mixed>|null
     */
    #[Reactive]
    #[Locked]
    public ?array $context = null;

    public int $minChars = 2;

    public string $placeholder = '';

    public function select(string $value): void
    {
        $this->value = $value;
    }

    public function render(): View
    {
        return view('livewire.combobox', [
            'options' => $this->options(),
        ]);
    }

    /**
     * Matching options, or an empty array while below the character threshold —
     * the provider is not touched until then, so nothing is loaded early.
     *
     * @return array<int, array{value: string, label: string}>
     */
    protected function options(): array
    {
        if (mb_strlen(trim($this->value)) < $this->minChars) {
            return [];
        }

        $provider = app($this->provider);

        if (! $provider instanceof ComboboxOptionProvider) {
            return [];
        }

        return $provider->options(trim($this->value), $this->context ?? [])->all();
    }
}

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

    /*
    | FQCN of a ComboboxOptionProvider.
    |
    | #[Locked] IS UPSTREAM'S, and adding a second one is a fatal error --
    | "Attribute must not be repeated" at render, which took out 41 view tests.
    | It was added here on the belief that the lock was missing, from reading
    | this file rather than the base commit. Check before you protect: a guard
    | that is already there does not need a second one, and PHP treats the
    | duplicate as an error rather than a no-op.
    |
    | Why it matters, since the reason is worth keeping: `options()` does
    | `app($this->provider)`, resolving a class name out of the container.
    | Livewire rehydrates public properties from the request payload, so without
    | the lock the class name would come from the browser. The
    | `instanceof ComboboxOptionProvider` check stops the RESULT of a wrong
    | class being returned, not the class being BUILT -- `app()` instantiates
    | before anything is checked.
    |
    | `$context` below carries our own #[Locked], and that one IS ours -- upstream
    | leaves it #[Reactive] alone. It is belt and braces rather than a fix: its
    | only consumer applies the caller's scope before letting context narrow, so
    | a tampered value can only shrink an already-scoped result. Locking it means
    | the next provider cannot get that wrong.
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

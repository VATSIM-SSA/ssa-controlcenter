<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

/**
 * VATSSA: pages that host the Discord rule Livewire components.
 * Matrix: vatssa.discord.rules.view and .manage.
 */
class VatssaDiscordRuleController extends Controller
{
    public function index(): View
    {
        abort_unless(Auth::user()->hasPermission('vatssa.discord.rules.view'), 403);

        return view('vatssa.discord.rules.index');
    }

    public function create(): View
    {
        abort_unless(Auth::user()->hasPermission('vatssa.discord.rules.manage'), 403);

        return view('vatssa.discord.rules.form', ['ruleId' => null]);
    }

    public function edit(int $rule): View
    {
        abort_unless(Auth::user()->hasPermission('vatssa.discord.rules.manage'), 403);

        return view('vatssa.discord.rules.form', ['ruleId' => $rule]);
    }
}

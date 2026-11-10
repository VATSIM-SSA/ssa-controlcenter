<?php

namespace App\Http\Controllers;

use App\Models\Acknowledgement;
use Illuminate\Http\RedirectResponse;

class AcknowledgementController extends Controller
{
    /**
     * The member confirms they have read a notice, which takes it off their dashboard.
     */
    public function acknowledge(Acknowledgement $acknowledgement): RedirectResponse
    {
        $this->authorize('acknowledge', $acknowledgement);

        $acknowledgement->acknowledge();

        return back()->with('success', 'Thank you for acknowledging.');
    }
}

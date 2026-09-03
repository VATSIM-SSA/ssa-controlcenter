<?php

namespace App\Http\Requests;

use App\Helpers\FeedbackSentiment;
use App\Helpers\FeedbackStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class ActionFeedbackRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $feedback = $this->route('feedback');

        return $feedback !== null && (bool) $this->user()?->can('action', $feedback);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            // Only the two outcomes staff may choose. `open` is not among them:
            // feedback arrives open and leaves, and un-actioning would discard
            // who decided and when. See FeedbackStatus::actionable().
            'status' => ['required', Rule::in(array_column(FeedbackStatus::actionable(), 'value'))],

            // Optional, because a division that does not want to categorise
            // should not be forced to, and a sentiment guessed at to get past a
            // required field is worse than none.
            'sentiment' => ['nullable', new Enum(FeedbackSentiment::class)],

            'staff_note' => 'nullable|string|max:2000',
        ];
    }

    /**
     * Get custom error messages for validation rules.
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Choose whether to close this feedback or forward it.',
            'status.in' => 'Feedback can only be closed or forwarded.',
        ];
    }
}

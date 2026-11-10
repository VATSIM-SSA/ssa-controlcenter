<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFeedbackRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $feedback = $this->route('feedback');

        return $feedback !== null && (bool) $this->user()?->can('update', $feedback);
    }

    /**
     * Callsigns and airport codes are stored uppercase, however they were typed.
     */
    protected function prepareForValidation(): void
    {
        foreach (['session_callsign', 'pilot_callsign', 'departure_airport', 'arrival_airport'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => strtoupper(trim($this->input($field)))]);
            }
        }
    }

    /**
     * Get the validation rules that apply to the request. Everything the
     * submitter gave can be corrected except who they are and what they wrote.
     */
    public function rules(): array
    {
        return [
            'session_callsign' => ['nullable', 'string', 'max:20', 'regex:/^[A-Z0-9_]+$/'],
            'session_started_at' => ['nullable', 'required_with:session_callsign', 'date'],
            'session_ended_at' => ['nullable', 'date', 'after_or_equal:session_started_at'],
            'pilot_callsign' => ['nullable', 'string', 'max:20', 'regex:/^[A-Z0-9_-]+$/'],
            'event_date' => 'nullable|date_format:Y-m-d|before_or_equal:today',
            'departure_airport' => ['nullable', 'string', 'regex:/^[A-Z0-9]{4}$/'],
            'arrival_airport' => ['nullable', 'string', 'regex:/^[A-Z0-9]{4}$/'],
            'position' => 'nullable|exists:positions,callsign',
            'controller' => 'nullable|numeric|exists:users,id',
            'area' => [
                'nullable',
                'exists:areas,id',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (filled($value) && $this->filled('position')) {
                        $fail('An explicit area cannot be set when a position is selected.');
                    }
                },
            ],
        ];
    }

    /**
     * Get custom error messages for validation rules.
     */
    public function messages(): array
    {
        return [
            'controller.numeric' => 'The controller field must be a valid VATSIM CID (numeric).',
            'controller.exists' => 'A controller with this CID was not found.',
            'position.exists' => 'The position does not exist.',
            'session_started_at.required_with' => 'Give the session start time with its callsign.',
            'pilot_callsign.regex' => 'The callsign may only contain letters, digits, dashes and underscores.',
            'event_date.before_or_equal' => 'The date of the event cannot be in the future.',
            'departure_airport.regex' => 'The departure airport must be a 4 character ICAO code.',
            'arrival_airport.regex' => 'The arrival airport must be a 4 character ICAO code.',
        ];
    }
}

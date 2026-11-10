<?php

namespace App\Http\Requests;

use App\Models\Area;
use Illuminate\Foundation\Http\FormRequest;

class StoreFeedbackRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Callsigns and airport codes are stored uppercase, however they were typed.
     */
    protected function prepareForValidation(): void
    {
        foreach (['pilot_callsign', 'departure_airport', 'arrival_airport'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => strtoupper($this->input($field))]);
            }
        }
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            // With several areas the submitter picks one first, so the entry reaches the right staff.
            'area' => [Area::count() > 1 ? 'required' : 'nullable', 'integer', 'exists:areas,id'],
            'controller' => ['required', 'numeric', 'exists:users,id', 'not_in:' . $this->user()?->id],
            'position' => 'nullable|exists:positions,callsign',
            'session_callsign' => ['nullable', 'string', 'max:20', 'regex:/^[A-Z0-9_]+$/'],
            'session_started_at' => ['nullable', 'required_with:session_callsign', 'date', 'before_or_equal:now'],
            'session_ended_at' => ['nullable', 'date', 'after_or_equal:session_started_at'],
            'pilot_callsign' => ['nullable', 'string', 'max:20', 'regex:/^[A-Z0-9_-]+$/'],
            'event_date' => 'nullable|date_format:Y-m-d|before_or_equal:today',
            'departure_airport' => ['nullable', 'string', 'regex:/^[A-Z0-9]{4}$/'],
            'arrival_airport' => ['nullable', 'string', 'regex:/^[A-Z0-9]{4}$/'],
            'feedback' => 'required|string|max:16000',
        ];
    }

    /**
     * Get custom error messages for validation rules.
     */
    public function messages(): array
    {
        return [
            'area.required' => 'Choose the area your feedback is about.',
            'controller.required' => 'Choose the controller your feedback is about.',
            'controller.numeric' => 'The controller field must be a valid VATSIM CID (numeric).',
            'controller.exists' => 'A controller with this CID was not found.',
            'controller.not_in' => 'You cannot give feedback about yourself.',
            'position.exists' => 'The position does not exist.',
            'pilot_callsign.regex' => 'The callsign may only contain letters, digits, dashes and underscores.',
            'event_date.before_or_equal' => 'The date of the event cannot be in the future.',
            'departure_airport.regex' => 'The departure airport must be a 4 character ICAO code.',
            'arrival_airport.regex' => 'The arrival airport must be a 4 character ICAO code.',
        ];
    }
}

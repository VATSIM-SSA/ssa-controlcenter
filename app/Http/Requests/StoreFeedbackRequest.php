<?php

namespace App\Http\Requests;

use App\Models\Vatssa\FeedbackType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'position' => 'nullable|exists:positions,callsign',
            'controller' => 'nullable|numeric|exists:users,id',
            'feedback' => 'required|string|max:16000',
            // VATSSA: what kind of feedback this is. Required, because the
            // whole point is that the team can sort the queue -- an optional
            // field on a public form is an empty column.
            'vatssa_type' => ['required', 'string', Rule::in(array_keys(FeedbackType::map(true)))],
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
            'vatssa_type.required' => 'Please say what kind of feedback this is.',
        ];
    }
}

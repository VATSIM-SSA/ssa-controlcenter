<?php

namespace App\Http\Requests;

use App\Helpers\FeedbackDelivery;
use App\Models\Feedback;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Send a feedback to its controller: as written, or as a rewritten version.
 */
class SendFeedbackRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $feedback = $this->feedback();

        return $feedback !== null && (bool) $this->user()?->can('review', $feedback);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'delivery' => ['required', Rule::enum(FeedbackDelivery::class), $this->sendableRule()],
            'rewritten' => ['nullable', 'required_if:delivery,' . FeedbackDelivery::REWRITTEN->value, 'string', 'max:16000'],
            'discord' => ['nullable', 'boolean'],
            'comment' => ['nullable', 'string', 'max:4000'],
        ];
    }

    /**
     * Get custom error messages for validation rules.
     */
    public function messages(): array
    {
        return [
            'delivery.required' => 'Choose what to send the controller.',
            'rewritten.required_if' => 'Write the version to send the controller.',
        ];
    }

    public function delivery(): FeedbackDelivery
    {
        return FeedbackDelivery::from($this->validated('delivery'));
    }

    public function postToDiscord(): bool
    {
        return $this->boolean('discord');
    }

    /**
     * Sending needs a controller, and nothing already with them: a controller
     * holds one version at a time.
     */
    protected function sendableRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($this->feedback()?->reference_user_id === null) {
                $fail('Set the controller this feedback is about before sending it.');
            } elseif ($this->feedback()->wasSent()) {
                $fail('The controller already has a version of this feedback. Withdraw it before sending another.');
            }
        };
    }

    protected function feedback(): ?Feedback
    {
        return $this->route('feedback');
    }
}

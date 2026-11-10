<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The three Discord webhook calls the feedback features need: post a message,
 * edit it, delete it.
 *
 * A webhook URL is a secret, and HTTP exception messages contain it, so
 * failures log only the exception class or the HTTP status.
 */
class DiscordWebhook
{
    private const TIMEOUT_SECONDS = 5;

    /**
     * Post a message and return its id, or null when Discord did not accept it.
     *
     * @param  array<string, mixed>  $payload
     */
    public function post(string $url, array $payload): ?string
    {
        try {
            // wait=true makes Discord return the message, whose id lets it be edited or deleted later.
            $response = Http::timeout(self::TIMEOUT_SECONDS)->asJson()->withQueryParameters(['wait' => 'true'])->post($url, $payload);
        } catch (Throwable $e) {
            Log::warning('Could not post to Discord', ['error' => $e::class]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Discord rejected a post', ['status' => $response->status()]);

            return null;
        }

        $id = $response->json('id');

        return is_string($id) ? $id : null;
    }

    /**
     * Replace a message's content.
     *
     * @param  array<string, mixed>  $payload
     * @return bool|null true when edited, false when it failed, null when the
     *                   message no longer exists (deleted, or posted by another webhook)
     */
    public function edit(string $url, string $messageId, array $payload): ?bool
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)->asJson()->patch($url . '/messages/' . $messageId, $payload);
        } catch (Throwable $e) {
            Log::warning('Could not edit a Discord message', ['error' => $e::class]);

            return false;
        }

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            Log::warning('Discord refused to edit a message', ['status' => $response->status()]);

            return false;
        }

        return true;
    }

    /**
     * Delete a message. One that is already gone counts as deleted.
     */
    public function delete(string $url, string $messageId): bool
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)->delete($url . '/messages/' . $messageId);
        } catch (Throwable $e) {
            Log::warning('Could not delete a Discord message', ['error' => $e::class]);

            return false;
        }

        if (! $response->successful() && $response->status() !== 404) {
            Log::warning('Discord refused to delete a message', ['status' => $response->status()]);

            return false;
        }

        return true;
    }
}

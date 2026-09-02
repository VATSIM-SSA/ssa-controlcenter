<?php

namespace Tests\Feature\Api\V1;

use App\Models\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_v1_user_endpoint_rejects_missing_auth(): void
    {
        $this->getJson('/api/v1/user')->assertUnauthorized();
    }

    public function test_v1_booking_create_rejects_missing_bearer_token(): void
    {
        $this->postJson('/api/v1/bookings/create', [])->assertUnauthorized();
    }

    public function test_v1_booking_create_rejects_read_only_key(): void
    {
        // VATSSA: the token is hashed at rest, so the row is keyed on the
        // hash rather than on the token itself. What this test asserts --
        // that a read-only key cannot write -- is unchanged.
        ApiKey::create(['id' => 'ro-key', 'token_hash' => ApiKey::hashToken('ro-key'), 'name' => 't', 'read_only' => true, 'created_at' => now()]);

        $this->withToken('ro-key')->postJson('/api/v1/bookings/create', [])->assertUnauthorized();
    }
}

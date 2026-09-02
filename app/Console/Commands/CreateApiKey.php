<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use Illuminate\Console\Command;
use Ramsey\Uuid\Uuid;

class CreateApiKey extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create:apikey {--expires=0 : Days until the key expires. 0 means it never does.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Creates an API key';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Gather details from input
        $choices = [
            'NO, read only',
            'YES, allow editing data',
        ];
        $choice = $this->choice('Should the API key have edit rights?', $choices);
        $readonly = $choice == $choices[0];

        $name = $this->ask('What should we name the API Key?');

        // VATSSA: shown once, stored hashed.
        //
        // 32 random bytes rather than a UUID -- a UUIDv4 carries 122 bits and
        // four of its characters are fixed, which is fine, but there is no
        // reason for a bearer token to be recognisable as a UUID at all.
        $secret = bin2hex(random_bytes(32));

        $days = (int) $this->option('expires');

        ApiKey::create([
            'id' => (string) Uuid::uuid4(),
            'token_hash' => ApiKey::hashToken($secret),
            'name' => $name,
            'read_only' => $readonly,
            'created_at' => now(),
            'expires_at' => $days > 0 ? now()->addDays($days) : null,
        ]);

        $this->comment('API key `' . $name . '` created. Token, shown ONCE:');
        $this->line('');
        $this->line('  ' . $secret);
        $this->line('');
        $this->warn('It is stored hashed, so this cannot be recovered. Put it somewhere safe now.');

        if ($days > 0) {
            $this->comment('It expires in ' . $days . ' day(s).');
        } else {
            $this->warn('No expiry set. Pass --expires=<days> next time.');
        }
    }
}

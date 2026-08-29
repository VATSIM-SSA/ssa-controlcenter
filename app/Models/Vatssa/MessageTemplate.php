<?php

namespace App\Models\Vatssa;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * VATSSA: one of the bot's messages, editable in Control Center.
 *
 * Control Center's own template editor cannot carry these. Read at source: it
 * is append-only, on three emails, per area. You add a paragraph; you cannot
 * rewrite the body, which is hardcoded in Blade.
 */
class MessageTemplate extends Model
{
    protected $table = 'vatssa_message_templates';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'key', 'name', 'subject', 'body', 'channel', 'description', 'updated_by',
    ];

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The placeholders this template uses, such as {name} or {days_left}.
     *
     * The bot raises at render time rather than emailing a raw brace when a
     * template asks for something it cannot supply, so the admin page lists
     * these and whoever is editing can see what is safe to use.
     */
    public function placeholders(): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $this->subject.' '.$this->body, $found);

        return array_values(array_unique($found[1] ?? []));
    }
}

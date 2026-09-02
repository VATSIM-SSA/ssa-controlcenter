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
     * Control Center's own three emails, as editable rows.
     *
     * `MentorLostNotification`, `StudentRemovedFromMentorNotification` and
     * `RosterExpiringNotification` are ours rather than upstream's and were
     * written in PHP, so rewording one meant a developer and a deploy while
     * every other message in the pipeline could be edited on a page. Same
     * table, same editor, same audit trail.
     */
    public const MENTOR_LOST = 'V1';

    public const STUDENT_REMOVED = 'V2';

    public const ROSTER_EXPIRING = 'V3';

    /**
     * Subject and body for one of ours, with the placeholders filled in.
     *
     * Returns null when the row is missing, and the caller then sends the text
     * it was compiled with. That fallback is the whole reason this is safe: a
     * deleted row, a database that has not been migrated, a typo in a key --
     * none of them may turn into a member receiving nothing. A worse-worded
     * email beats a missing one, every time.
     *
     * @param  array<string, string|null>  $values
     * @return array{subject: string, lines: array<int, string>}|null
     */
    public static function compose(string $key, array $values): ?array
    {
        $template = static::find($key);

        if ($template === null || trim((string) $template->body) === '') {
            return null;
        }

        $replace = [];
        foreach ($values as $name => $value) {
            $replace['{' . $name . '}'] = (string) ($value ?? '');
        }

        $body = strtr($template->body, $replace);

        // A blank line is a paragraph, because that is what somebody typing
        // into a textarea means by one. TrainingMail takes an array of them.
        $lines = preg_split('/\R{2,}/', trim($body)) ?: [];

        return [
            'subject' => trim(strtr((string) $template->subject, $replace)),
            'lines' => array_values(array_filter(array_map('trim', $lines), fn ($line) => $line !== '')),
        ];
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
        preg_match_all('/\{([a-z_]+)\}/', $this->subject . ' ' . $this->body, $found);

        return array_values(array_unique($found[1] ?? []));
    }
}

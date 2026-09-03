<?php

namespace App\Models\Vatssa;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * VATSSA: a canned Terminal comment, keyed by code.
 *
 * ## What this is FOR, and it is not filing
 *
 * The text produced here gets PASTED INTO TERMINAL as the justification for the
 * access. So the job of a row is to produce copy-ready text, and the copy
 * button beside it is the feature rather than a convenience -- a catalogue you
 * have to retype from is a catalogue people stop using by the third entry.
 *
 * ## One entry per KIND, not per case
 *
 * The rating-update comment covers every upgrade through placeholders, rather
 * than there being one row for S1→S2 and another for S2→S3. Sixteen rows that
 * differ by two characters is a list nobody can scan, and it goes stale the
 * moment a rating is added.
 *
 * ## The shape is fixed
 *
 * `SSA | <TEAM> - <text>`. Every comment VATSSA leaves on Terminal reads that
 * way, so the shape lives here and an editor only writes the text.
 */
class TerminalComment extends Model
{
    protected $table = 'vatssa_terminal_comments';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['code', 'label', 'team', 'category', 'body', 'description', 'sort_order', 'active'];

    protected $casts = [
        'active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * The finished comment, ready to paste.
     *
     * Placeholders are `{name}` style and unknown ones are left ALONE rather
     * than blanked. A comment that silently loses a value reads as complete and
     * is not; one that still says `{to}` is obviously unfinished, and obviously
     * unfinished is the safer failure when the text is about to go on somebody's
     * permanent record.
     *
     * @param  array<string, string|null>  $values
     */
    public function compose(array $values = []): string
    {
        $body = $this->body;

        foreach ($values as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $body = str_replace('{' . $key . '}', (string) $value, $body);
        }

        return sprintf('SSA | %s - %s', $this->team, $body);
    }

    /**
     * The placeholders this comment expects.
     *
     * Shown on the admin page so whoever is editing can see what is safe to
     * use, and used by the log form to know which fields to offer.
     *
     * @return array<int, string>
     */
    public function placeholders(): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $this->body, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /** @return Builder<self> */
    public static function offered()
    {
        return static::where('active', true)->orderBy('sort_order')->orderBy('code');
    }
}

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// VATSSA: the first Discord rules, equal to the bot's old hard-coded ones. Refuses if any
// rule exists. Role IDs are the production server's; a test server's are changed on the page.
class VatssaDiscordSeedRules extends Command
{
    protected $signature = 'vatssa:discord-seed-rules';

    protected $description = 'Create the starting Discord role rules';

    private const NOT_VATSIM = '1552239343182024775';

    private const VISITOR = '986216721046175785';

    private const MEMBER = '986216672685867038';

    private const TRAINEE = '1037453067303526421';

    private const MENTOR = '986212946487148574';

    private const EXAMINER = '986213129891504158';

    private const SOLO = '1552240522285023232';

    private const FSS = '986213832294805504';

    private const APPROVED = '1037376361137979442';

    private const GND = '986213502253428816';

    private const TWR = '986213753718722571';

    private const APP = '986213568892506153';

    private const CTR = '986213786690158634';

    private const ADM = '1496139792897933392';

    private const SUS_INA = '1572712830019440793';

    public function handle(): int
    {
        if (DB::table('vatssa_discord_rules')->exists()) {
            $this->error('Rules already exist. Nothing changed.');

            return self::FAILURE;
        }

        $c = fn (string $type, array $params = []) => ['type' => $type, 'params' => $params];
        $home = $c('division', ['value' => 'home']);
        $visitor = $c('division', ['value' => 'visitor']);
        $roster = $c('roster', ['area' => 'any']);
        $rating = fn (int $value) => $c('rating', ['mode' => 'in', 'values' => [$value]]);
        $visiting = fn (string $name, string $compare = 'highest') => $c('endorsement', ['type' => 'VISITING', 'rating' => $name, 'compare' => $compare]);

        $rules = [
            ['Not VATSIM Member', [$c('not_vatsim')], self::NOT_VATSIM],
            ['Member', [$home], self::MEMBER],
            ['Visitor', [$visitor], self::VISITOR],
            ['Trainee', [$c('training', ['area' => 'any'])], self::TRAINEE],
            ['Mentor', [$c('cc_role', ['role' => 'mentor', 'area' => 'any'])], self::MENTOR],
            ['Examiner', [$c('endorsement', ['type' => 'EXAMINER'])], self::EXAMINER],
            ['SOLO', [$c('endorsement', ['type' => 'SOLO'])], self::SOLO],
            ['FSS', [$c('endorsement', ['type' => 'FACILITY', 'rating' => 'AFRx FSS', 'compare' => 'any'])], self::FSS],
            ['Approved: home', [$home, $roster], self::APPROVED],
            ['Approved: visitor', [$visitor, $c('endorsement', ['type' => 'VISITING'])], self::APPROVED],
            ['ATC GND: home', [$home, $roster, $rating(2)], self::GND],
            ['ATC TWR: home', [$home, $roster, $rating(3)], self::TWR],
            ['ATC APP: home', [$home, $roster, $rating(4)], self::APP],
            ['ATC CTR: home', [$home, $roster, $c('rating', ['mode' => 'min', 'value' => 5])], self::CTR],
            ['ATC GND: visitor', [$visitor, $visiting('S1')], self::GND],
            ['ATC TWR: visitor', [$visitor, $visiting('S2')], self::TWR],
            ['ATC APP: visitor', [$visitor, $visiting('S3')], self::APP],
            ['ATC CTR: visitor', [$visitor, $visiting('C1', 'at_least')], self::CTR],
            ['ADM', [$rating(12)], self::ADM],
            ['INA / SUS', [$c('rating', ['mode' => 'in', 'values' => [-1, 0]])], self::SUS_INA],
        ];

        DB::transaction(function () use ($rules) {
            foreach ($rules as [$name, $conditions, $role]) {
                $id = DB::table('vatssa_discord_rules')->insertGetId(['name' => $name, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
                foreach ($conditions as $condition) {
                    DB::table('vatssa_discord_rule_conditions')->insert(['rule_id' => $id, 'type' => $condition['type'], 'params' => json_encode($condition['params']), 'negated' => false]);
                }
                DB::table('vatssa_discord_rule_roles')->insert(['rule_id' => $id, 'discord_role_id' => $role]);
                DB::table('vatssa_discord_rule_audit')->insert(['rule_id' => $id, 'rule_name' => $name, 'action' => 'seeded',
                    'after' => json_encode(['name' => $name, 'description' => null, 'enabled' => true, 'conditions' => $conditions, 'roles' => [$role]]), 'created_at' => now()]);
            }
        });
        $this->info(count($rules) . ' rules created');

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Read-only rollout inventory, safe before or after the additive foundation migration. */
class RegistrationPreflight extends Command
{
    protected $signature = 'registration:preflight';

    protected $description = 'Check canonical identities and legacy verification access without changing accounts';

    public function handle(): int
    {
        $canonical = "lower(btrim(email, E' \\t\\r\\n'))";
        $groups = DB::table('users')->selectRaw('1')->groupByRaw($canonical)->havingRaw('count(*) > 1');
        $counts = [
            'canonical_collision_groups' => DB::query()->fromSub($groups, 'identity_groups')->count(),
            'invalid_canonical_emails' => DB::table('users')->whereRaw("octet_length($canonical) NOT BETWEEN 1 AND 254 OR $canonical ~ '[^!-~]'")->count(),
            'administrators' => DB::table('users')->where('role', 'administrator')->count(),
            'verified_administrators' => DB::table('users')->where('role', 'administrator')->whereNotNull('email_verified_at')->count(),
            'unverified_accounts' => DB::table('users')->whereNull('email_verified_at')->count(),
        ];
        $this->line(json_encode($counts, JSON_THROW_ON_ERROR));
        if ($counts['canonical_collision_groups'] > 0 || $counts['invalid_canonical_emails'] > 0) {
            $this->error('Stop: canonical identities need approved review. No data changed.');

            return self::FAILURE;
        }
        if ($counts['administrators'] > 0 && $counts['verified_administrators'] === 0) {
            $this->error('Stop: Administrator verification/recovery must be reviewed before rollout. Never fabricate verification. No data changed.');

            return self::FAILURE;
        }
        if ($counts['unverified_accounts'] > 0) {
            $this->warn('Unverified accounts will retain sign-in/profile only until verification. Review this access impact before rollout.');
        }
        $this->info('Preflight passed. This does not verify email, send mail, migrate, or enable registration.');

        return self::SUCCESS;
    }
}

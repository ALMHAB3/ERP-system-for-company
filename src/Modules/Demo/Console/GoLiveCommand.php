<?php

declare(strict_types=1);

namespace Modules\Demo\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Modules\Audit\Application\AuditLogger;
use Modules\Identity\Domain\AccessRepository;

/**
 * Ends the trial period: erases every record (sample data, demo users, test sales, test payroll, the audit trail of
 * the trial ...) and leaves an empty system ready for real work. The reference data that every installation needs
 * (chart of accounts, currencies, roles, the cash customer) is recreated, and the administrator accounts are kept
 * with their e-mail and password, so you sign in exactly as before.
 *
 * Refuses on a system that never had sample data, unless --force: on real data this would be a disaster.
 */
final class GoLiveCommand extends Command
{
    protected $signature = 'erp:go-live
        {--confirm= : type RESET here to skip the question (for scripts)}
        {--force : also run on a system without sample data (erases REAL data)}';

    protected $description = 'Erase all sample/test data and start real work on an empty system (administrator accounts are kept).';

    public function handle(AccessRepository $access, AuditLogger $audit): int
    {
        $hadDemo = DB::table('system_flags')->where('name', 'demo_data')->exists();
        if (! $hadDemo && ! $this->option('force')) {
            $this->error('This system was never loaded with sample data: it looks like REAL data. Nothing was erased.');
            $this->line('If you are sure every record must go, run again with --force (take a backup first).');

            return self::FAILURE;
        }

        // Administrators who are not demo accounts keep their login (same e-mail, name and password).
        $admins = DB::table('users as u')->join('role_user as ru', 'ru.user_id', '=', 'u.id')->join('roles as r', 'r.id', '=', 'ru.role_id')
            ->where('r.name', 'admin')->where('u.active', true)->where('u.email', 'not like', '%@demo.test')
            ->select('u.name', 'u.email', 'u.password')->distinct()->get()->map(fn ($u) => (array) $u)->all();

        $this->warn('This ERASES every record in the system: products, stock, shipments, sales, invoices, payments, customers,');
        $this->warn('suppliers, employees, payroll, journal entries, users and the audit trail. It cannot be undone.');
        $this->line($admins === []
            ? 'No administrator account will be kept: you will create one at the end.'
            : 'Administrator accounts kept (same e-mail and password): '.implode(', ', array_column($admins, 'email')));
        $word = $this->option('confirm') ?? $this->ask('Type RESET to continue');
        if ($word !== 'RESET') {
            $this->line('Cancelled. Nothing was changed.');

            return self::FAILURE;
        }

        $newAdmin = null;
        if ($admins === []) {
            $newAdmin = $this->askNewAdmin();
            if ($newAdmin === null) {
                return self::FAILURE;
            }
        }

        $this->line('Erasing ...');
        Artisan::call('migrate:fresh', ['--force' => true]);

        DB::transaction(function () use ($admins, $newAdmin, $access, $audit, $hadDemo) {
            $ids = [];
            foreach ($admins as $a) {
                // The stored hash is copied as it is, so the password stays exactly what it was.
                $ids[] = DB::table('users')->insertGetId($a + ['active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
            if ($newAdmin !== null) {
                $ids[] = User::query()->create($newAdmin + ['active' => true])->id;
            }
            foreach ($ids as $id) {
                $access->replaceAccess($id, ['admin'], []);
            }
            $audit->record('system.went_live', 'system', null, ['sample_data_removed' => $hadDemo, 'administrators' => count($ids)]);
        });

        $this->info('Done. The system is empty and ready for real work.');
        $this->line('Sign in as the administrator and follow the "Getting started" checklist on the dashboard.');

        return self::SUCCESS;
    }

    /** @return array{name:string,email:string,password:string}|null */
    private function askNewAdmin(): ?array
    {
        $data = [
            'name' => (string) ($this->ask('Administrator name', 'Administrator')),
            'email' => (string) $this->ask('Administrator e-mail (login)'),
            'password' => (string) $this->secret('Password (min 12 chars, mixed case, numbers)'),
            'password_confirmation' => (string) $this->secret('Repeat the password'),
        ];
        $validator = Validator::make($data, ['name' => ['required', 'max:120'], 'email' => ['required', 'email', 'max:190'], 'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()]]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            $this->line('Nothing was changed.');

            return null;
        }
        unset($data['password_confirmation']);

        return $data;
    }
}

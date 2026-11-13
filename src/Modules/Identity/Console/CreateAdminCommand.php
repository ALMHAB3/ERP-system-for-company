<?php

declare(strict_types=1);

namespace Modules\Identity\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Modules\Audit\Application\AuditLogger;
use Modules\Identity\Domain\AccessRepository;

final class CreateAdminCommand extends Command
{
    protected $signature = 'erp:create-admin {email} {--name=Administrator}';

    protected $description = 'Create the first administrator (password is prompted, never passed on the command line).';

    public function handle(AccessRepository $access, AuditLogger $audit): int
    {
        $password = (string) $this->secret('Password (min 12 chars, mixed case, numbers)');
        $validator = Validator::make(
            ['email' => $this->argument('email'), 'password' => $password],
            ['email' => ['required', 'email', 'unique:users,email'], 'password' => ['required', Password::min(12)->mixedCase()->numbers()]],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($access, $audit, $password) {
            $user = User::query()->create(['name' => $this->option('name'), 'email' => $this->argument('email'), 'password' => $password, 'active' => true]);
            $access->replaceAccess($user->id, ['admin'], []);
            $audit->record('user.created', 'user', $user->id, ['roles' => ['admin'], 'via' => 'console']);
        });
        $this->info('Administrator created.');

        return self::SUCCESS;
    }
}

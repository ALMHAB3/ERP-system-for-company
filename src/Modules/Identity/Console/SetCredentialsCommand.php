<?php

declare(strict_types=1);

namespace Modules\Identity\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Modules\Audit\Application\AuditLogger;

/**
 * Recovery from the server console when a password is forgotten (or the administrator's login must change).
 * Only someone with shell access to the server can run it. The new password is prompted, never passed on the command line.
 */
final class SetCredentialsCommand extends Command
{
    protected $signature = 'erp:set-credentials {email : the current login e-mail of the account}
        {--new-email= : change the login e-mail}
        {--name= : change the display name}
        {--keep-password : change only the e-mail/name}';

    protected $description = 'Change a user\'s login e-mail, name and/or password from the server console (signs out all their sessions).';

    public function handle(AuditLogger $audit): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();
        if ($user === null) {
            $this->error('No user has the e-mail '.$this->argument('email').'.');

            return self::FAILURE;
        }

        $changes = array_filter(['email' => $this->option('new-email'), 'name' => $this->option('name')], static fn ($v) => $v !== null && $v !== '');
        $rules = [
            'email' => ['email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'name' => ['string', 'max:120'],
        ];
        if (! $this->option('keep-password')) {
            $changes['password'] = (string) $this->secret('New password (min 12 chars, mixed case, numbers)');
            $changes['password_confirmation'] = (string) $this->secret('Repeat the new password');
            $rules['password'] = ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()];
        }
        if ($changes === []) {
            $this->error('Nothing to change: give --new-email and/or --name, or leave out --keep-password to set a new password.');

            return self::FAILURE;
        }
        $validator = Validator::make($changes, $rules);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        unset($changes['password_confirmation']);

        DB::transaction(function () use ($user, $changes, $audit) {
            $user->forceFill($changes + ['active' => true])->save();
            $user->tokens()->delete();
            $audit->record('user.credentials_set', 'user', $user->id, [
                'name' => $changes['name'] ?? null, 'email' => $changes['email'] ?? null, 'password_changed' => isset($changes['password']), 'via' => 'console',
            ]);
        });
        $this->info('Saved. The account is active and all its sessions were signed out; sign in with the new details.');

        return self::SUCCESS;
    }
}

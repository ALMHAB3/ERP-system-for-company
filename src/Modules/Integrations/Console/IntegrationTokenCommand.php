<?php

declare(strict_types=1);

namespace Modules\Integrations\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Modules\Audit\Application\AuditLogger;

final class IntegrationTokenCommand extends Command
{
    protected $signature = 'erp:integration-token {email : an existing (preferably dedicated, least-privilege) user} {--name=integration} {--days=365}';

    protected $description = 'Issue a long-lived API token for an external system. It can do exactly what that user can do, so give the user only the role it needs.';

    public function handle(AuditLogger $audit): int
    {
        $user = User::query()->where('email', $this->argument('email'))->where('active', true)->first();
        if ($user === null) {
            $this->error('No active user with that e-mail. Create one first (Users screen) with the narrowest role.');

            return self::FAILURE;
        }
        $token = $user->createToken((string) $this->option('name'), ['*'], now()->addDays(max(1, (int) $this->option('days'))));
        $audit->record('integration.token_issued', 'user', $user->id, ['name' => $this->option('name'), 'expires_at' => $token->accessToken->expires_at?->toIso8601String(), 'via' => 'console']);
        $this->line($token->plainTextToken);
        $this->warn('Shown once. Store it in the other system now. Revoke by deactivating the user or deleting the token.');

        return self::SUCCESS;
    }
}

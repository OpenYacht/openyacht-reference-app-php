<?php

namespace App\Console\Commands;

use App\Concerns\ProfileValidationRules;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Create an application user from the command line. Self-registration is
 * disabled, so this is how the first user gets into a fresh install —
 * and how later users are added until an admin user-creation UI exists.
 */
class OpenYachtCreateUser extends Command
{
    use ProfileValidationRules;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openyacht:create-user';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create an application user (self-registration is disabled)';

    public function handle(): int
    {
        $name = text(label: 'Name', required: true);
        $email = text(label: 'Email', required: true);
        $plainPassword = password(label: 'Password', required: true);
        $role = select(
            label: 'Role',
            options: array_column(Role::cases(), 'value', 'value'),
            default: Role::SuperAdmin->value,
        );

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $plainPassword],
            [...$this->profileRules(), 'password' => ['required', 'string', Password::default()]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $plainPassword,
        ]);
        $user->markEmailAsVerified();
        $user->assignRole($role);

        $this->info("User {$email} created with the {$role} role.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Brevo's HTTP API transport (MAIL_MAILER=brevo); email is optional
        // for a node, so the default mailer stays log until a key is set.
        Mail::extend('brevo', function (): mixed {
            return (new BrevoTransportFactory)->create(
                new Dsn('brevo+api', 'default', (string) config('services.brevo.key')),
            );
        });

        // The data API's OpenAPI spec (/docs/api.json): AuthenticateApiKey
        // accepts the key via the X-API-Key header or an Authorization
        // Bearer token — document both as alternatives. (No query-param
        // form: credentials in query strings end up in access logs.)
        Scramble::configure()
            ->afterOpenApiGenerated(function (OpenApi $openApi): void {
                $openApi->secure(SecurityScheme::apiKey('header', 'X-API-Key'));
                $openApi->secure(SecurityScheme::http('bearer')->as('bearerToken'));
            });

        // API docs are public: the API itself is the access boundary
        // (keys, scopes, domain locks), the documentation is not.
        Gate::define('viewApiDocs', fn (?User $user): bool => true);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}

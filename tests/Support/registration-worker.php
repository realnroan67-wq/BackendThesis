<?php

/** Guarded HTTP concurrency worker: committed test fixtures only, no external mail. */
use App\Models\OnboardingAuditEvent;
use App\Models\OnboardingNotification;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.connections.pgsql.database') !== 'horizon_realty_test'
    || config('database.connections.pgsql.username') !== 'horizon_test' || config('database.connections.pgsql.url')
    || preg_match('/\Aregistration_test_[a-f0-9]{16}\z/', config('database.connections.pgsql.search_path')) !== 1) {
    throw new RuntimeException('Registration worker requires a disposable isolated test schema.');
}
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
DB::select('SELECT set_config(\'application_name\', ?, false)', [$input['tag']]);
if (isset($input['notification_lock'])) {
    OnboardingNotification::creating(fn () => DB::select('SELECT pg_advisory_xact_lock(?)', [$input['notification_lock']]));
}
if (isset($input['audit_lock'])) {
    OnboardingAuditEvent::creating(fn () => DB::select('SELECT pg_advisory_xact_lock(?)', [$input['audit_lock']]));
}
$uri = '/register';
$method = 'POST';
if ($input['operation'] !== 'register') {
    $user = User::findOrFail($input['user_id']);
    Auth::guard('web')->setUser($user);
    $uri = '/email/verification-notification';
    if ($input['operation'] === 'verify') {
        $uri = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $method = 'GET';
    }
}
$request = Request::create($uri, $method, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
    content: json_encode($input['payload'] ?? [], JSON_THROW_ON_ERROR));
$kernel = $app->make(HttpKernel::class);
$response = $kernel->handle($request);
echo json_encode(['status' => $response->getStatusCode(), 'body' => $response->getContent()], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);

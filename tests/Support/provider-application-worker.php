<?php

/** Actual HTTP concurrency worker, confined to disposable PostgreSQL test schemas. */
use App\Models\OnboardingAuditEvent;
use App\Models\ProviderApplication;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.connections.pgsql.database') !== 'horizon_realty_test'
    || config('database.connections.pgsql.username') !== 'horizon_test' || config('database.connections.pgsql.url')
    || preg_match('/\Aapplication_test_[a-f0-9]{16}\z/', config('database.connections.pgsql.search_path')) !== 1) {
    throw new RuntimeException('Provider application workers require isolated test schemas.');
}
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
DB::select('SELECT set_config(\'application_name\', ?, false)', [$input['tag']]);
Auth::guard('web')->setUser(User::findOrFail($input['actor_id']));
$kernel = $app->make(HttpKernel::class);
$cookies = [];
$send = function (string $uri, string $method, array $body) use ($kernel, &$cookies) {
    $request = Request::create($uri, $method, cookies: $cookies, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
    $response = $kernel->handle($request);
    foreach ($response->headers->getCookies() as $cookie) {
        $cookies[$cookie->getName()] = $cookie->getValue();
    }
    $kernel->terminate($request, $response);

    return $response;
};
if ($input['operation'] === 'decision') {
    $response = $send('/api/v1/admin/password-confirmation', 'POST', ['password' => 'password']);
    if ($response->getStatusCode() !== 204) {
        echo json_encode(['status' => $response->getStatusCode(), 'body' => $response->getContent()]);
        exit;
    }
    if (isset($input['ready_lock'])) {
        DB::select('SELECT pg_advisory_lock(?)', [$input['ready_lock']]);
        DB::select('SELECT pg_advisory_unlock(?)', [$input['ready_lock']]);
    }
}
if (isset($input['application_lock'])) {
    ProviderApplication::creating(fn () => DB::select('SELECT pg_advisory_xact_lock(?)', [$input['application_lock']]));
}
if (isset($input['audit_lock'])) {
    OnboardingAuditEvent::creating(fn () => DB::select('SELECT pg_advisory_xact_lock(?)', [$input['audit_lock']]));
}
$response = $send($input['operation'] === 'decision' ? '/api/v1/admin/provider-applications/'.$input['id'].'/decision' : '/api/v1/provider-applications',
    $input['operation'] === 'decision' ? 'PATCH' : 'POST', $input['body']);
echo json_encode(['status' => $response->getStatusCode(), 'body' => $response->getContent()], JSON_THROW_ON_ERROR);

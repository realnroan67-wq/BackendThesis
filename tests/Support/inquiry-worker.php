<?php

/** Parallel integration worker: guarded to disposable schemas in the test database only. */
use App\Models\InquiryProviderGrant;
use App\Models\InquiryStatusChange;
use App\Models\Property;
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
    || config('database.connections.pgsql.username') !== 'horizon_test'
    || preg_match('/\Ainquiry_test_[a-f0-9]{16}\z/', config('database.connections.pgsql.search_path')) !== 1) {
    throw new RuntimeException('Parallel inquiry workers require an isolated test schema.');
}
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
DB::select('SELECT set_config(\'application_name\', ?, false)', [$input['tag']]);
try {
    if ($input['operation'] === 'transfer') {
        DB::transaction(function () use ($input): void {
            Property::query()->whereKey($input['property_id'])->lockForUpdate()->firstOrFail();
            // Simulates a future trusted transfer; no production transfer API is added.
            DB::table('properties')->where('id', $input['property_id'])->update(['provider_id' => $input['provider_id']]);
        });
        echo json_encode(['status' => 200], JSON_THROW_ON_ERROR);
    } elseif ($input['operation'] === 'revoke') {
        DB::transaction(function () use ($input): void {
            $grant = InquiryProviderGrant::query()->whereKey($input['grant_id'])->lockForUpdate()->firstOrFail();
            $grant->forceFill(['revoked_at' => now(), 'revoked_by' => $input['user_id'], 'revocation_reason' => 'Parallel QA revocation'])->save();
        });
        echo json_encode(['status' => 200], JSON_THROW_ON_ERROR);
    } else {
        if ($input['operation'] === 'close' && isset($input['audit_lock'])) {
            InquiryStatusChange::creating(function () use ($input): void {
                DB::select('SELECT pg_advisory_xact_lock(?)', [$input['audit_lock']]);
            });
        }
        Auth::guard('web')->setUser(User::findOrFail($input['user_id']));
        $isClose = $input['operation'] === 'close';
        $request = Request::create($isClose ? '/api/v1/provider/inquiries/'.$input['inquiry_id'].'/status' : '/api/v1/inquiries', $isClose ? 'PATCH' : 'POST', server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_IDEMPOTENCY_KEY' => $input['key'] ?? '',
        ], content: json_encode($isClose ? ['status' => 'closed'] : $input['payload'], JSON_THROW_ON_ERROR));
        $kernel = $app->make(HttpKernel::class);
        $response = $kernel->handle($request);
        echo json_encode(['status' => $response->getStatusCode(), 'body' => $response->getContent(),
            'location' => $response->headers->get('Location'), 'replayed' => $response->headers->get('Idempotency-Replayed')], JSON_THROW_ON_ERROR);
        $kernel->terminate($request, $response);
    }
} catch (Throwable $exception) {
    echo json_encode(['status' => 500, 'exception' => get_class($exception)], JSON_THROW_ON_ERROR);
}

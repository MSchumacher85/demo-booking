<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

/** @var array{database: array<string, mixed>, application_name: string, token: string, payload: array<string, mixed>} $input */
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

if (! $app->environment('testing')) {
    throw new RuntimeException('This worker is only available in the testing environment.');
}

config([
    'database.default' => 'pgsql',
    'database.connections.pgsql' => $input['database'],
    'session.driver' => 'array',
    'cache.default' => 'array',
]);
DB::purge('pgsql');
DB::selectOne("SELECT set_config('application_name', ?, false)", [$input['application_name']]);
DB::statement("SET lock_timeout TO '75s'");

$request = Request::create('/api/bookings', 'POST', server: [
    'HTTP_ACCEPT' => 'application/json',
    'CONTENT_TYPE' => 'application/json',
    'HTTP_AUTHORIZATION' => 'Bearer '.$input['token'],
], content: json_encode($input['payload'], JSON_THROW_ON_ERROR));
$response = $kernel->handle($request);

echo json_encode([
    'status' => $response->getStatusCode(),
    'body' => json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR),
], JSON_THROW_ON_ERROR);

$kernel->terminate($request, $response);

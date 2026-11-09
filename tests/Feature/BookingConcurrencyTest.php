<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BookingConcurrencyTest extends TestCase
{
    /**
     * Child processes need committed fixtures, so an enclosing test transaction cannot be used.
     */
    use DatabaseMigrations;

    /** @var array<string, Process> */
    private array $processes = [];

    private ?Connection $barrier = null;

    public function test_concurrent_overlaps_return_201_and_422_and_create_only_one_booking(): void
    {
        $room = Room::factory()->create();
        $payload = $this->bookingData($room);

        $responses = $this->runConcurrentRequests([$payload, $payload]);

        $this->assertSame([201, 422], collect($responses)->pluck('status')->sort()->values()->all());
        $rejected = collect($responses)->firstWhere('status', 422);
        $this->assertSame(
            ['Переговорная уже занята в выбранное время.'],
            $rejected['body']['errors']['starts_at'],
        );
        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseHas('bookings', [
            'room_id' => $room->id,
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
        ]);
    }

    public function test_concurrent_adjacent_bookings_both_succeed(): void
    {
        $room = Room::factory()->create();

        $responses = $this->runConcurrentRequests([
            $this->bookingData($room),
            $this->bookingData($room, '2026-11-02T11:00:00+03:00', '2026-11-02T12:00:00+03:00'),
        ]);

        $this->assertSame([201, 201], array_column($responses, 'status'));
        $this->assertDatabaseCount('bookings', 2);
        $this->assertDatabaseHas('bookings', [
            'room_id' => $room->id,
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
        ]);
        $this->assertDatabaseHas('bookings', [
            'room_id' => $room->id,
            'starts_at' => '2026-11-02 08:00:00',
            'ends_at' => '2026-11-02 09:00:00',
        ]);
    }

    public function test_booking_another_room_does_not_wait_for_a_locked_room(): void
    {
        $lockedRoom = Room::factory()->create();
        $otherRoom = Room::factory()->create();
        $barrier = $this->beginBarrierTransaction();
        $barrier->table('rooms')->where('id', $lockedRoom->id)->lockForUpdate()->first();
        $waitingRequest = $this->startRequest($this->bookingData($lockedRoom));
        $this->waitForBlockedRequests();

        $independentRequest = $this->startRequest($this->bookingData($otherRoom));
        $independentResponse = $this->readResponse($independentRequest);

        $this->assertSame(201, $independentResponse['status']);
        $this->assertTrue($waitingRequest->isRunning());
        $this->assertDatabaseHas('bookings', ['room_id' => $otherRoom->id]);
        $this->assertDatabaseMissing('bookings', ['room_id' => $lockedRoom->id]);

        $barrier->commit();

        $this->assertSame(201, $this->readResponse($waitingRequest)['status']);
        $this->assertDatabaseCount('bookings', 2);
        $this->assertDatabaseHas('bookings', ['room_id' => $lockedRoom->id]);
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }

            if ($this->barrier !== null) {
                if ($this->barrier->transactionLevel() > 0) {
                    $this->barrier->rollBack();
                }

                $this->barrier->disconnect();
            }
        } finally {
            parent::tearDown();
        }
    }

    /**
     * SHARE permits overlap checks but blocks INSERT, forcing requests to overlap in the database.
     *
     * @param  list<array{room_id: int, title: string, starts_at: string, ends_at: string}>  $payloads
     * @return list<array{status: int, body: array<string, mixed>}>
     */
    private function runConcurrentRequests(array $payloads): array
    {
        $barrier = $this->beginBarrierTransaction();
        $barrier->statement('LOCK TABLE bookings IN SHARE MODE');

        foreach ($payloads as $payload) {
            $this->startRequest($payload);
        }

        $this->waitForBlockedRequests();
        $barrier->commit();

        return array_values(array_map($this->readResponse(...), $this->processes));
    }

    private function beginBarrierTransaction(): Connection
    {
        $this->barrier = DB::build(config('database.connections.pgsql'));
        $this->barrier->beginTransaction();

        return $this->barrier;
    }

    /**
     * @param  array{room_id: int, title: string, starts_at: string, ends_at: string}  $payload
     */
    private function startRequest(array $payload): Process
    {
        $user = User::factory()->create();
        $applicationName = 'booking-concurrency-'.Str::uuid();
        $process = new Process(
            [PHP_BINARY, base_path('tests/Fixtures/booking-request.php')],
            base_path(),
            ['APP_ENV' => 'testing'],
        );
        $process->setTimeout(90);
        $process->setInput(json_encode([
            'database' => config('database.connections.pgsql'),
            'application_name' => $applicationName,
            'token' => $user->createToken('concurrency-test')->plainTextToken,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR));
        $this->processes[$applicationName] = $process;
        $process->start();

        return $process;
    }

    private function waitForBlockedRequests(): void
    {
        $deadline = microtime(true) + 60;

        do {
            foreach ($this->processes as $process) {
                if (! $process->isRunning()) {
                    $this->fail('Request finished before reaching the barrier: '.$process->getOutput().$process->getErrorOutput());
                }
            }

            $blocked = DB::table('pg_stat_activity')
                ->where('datname', DB::connection()->getDatabaseName())
                ->whereIn('application_name', array_keys($this->processes))
                ->where('wait_event_type', 'Lock')
                ->count();

            if ($blocked === count($this->processes)) {
                return;
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        $this->fail('Concurrent requests did not reach database locks within 60 seconds.');
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function readResponse(Process $process): array
    {
        $exitCode = $process->wait();
        $this->assertSame(0, $exitCode, $process->getErrorOutput().$process->getOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array{room_id: int, title: string, starts_at: string, ends_at: string} */
    private function bookingData(
        Room $room,
        string $start = '2026-11-02T10:00:00+03:00',
        string $end = '2026-11-02T11:00:00+03:00',
    ): array {
        return [
            'room_id' => $room->id,
            'title' => 'Одновременное бронирование',
            'starts_at' => $start,
            'ends_at' => $end,
        ];
    }
}

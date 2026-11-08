<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_booking_for_authenticated_user_and_stores_utc(): void
    {
        $user = User::factory()->create();
        $room = Room::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bookings', $this->bookingData($room));

        $response->assertCreated()
            ->assertJsonPath('user_id', $user->id)
            ->assertJsonPath('room_id', $room->id)
            ->assertJsonPath('title', 'Планёрка')
            ->assertJsonPath('starts_at', '2026-11-02T07:00:00.000000Z')
            ->assertJsonPath('ends_at', '2026-11-02T08:00:00.000000Z');
        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseHas('bookings', [
            'id' => $response->json('id'),
            'user_id' => $user->id,
            'room_id' => $room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
        ]);
    }

    public function test_ignores_client_supplied_author_and_unvalidated_attributes(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $room = Room::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bookings', [
            ...$this->bookingData($room),
            'user_id' => $other->id,
            'id' => 999999,
            'created_at' => '2000-01-01T00:00:00Z',
        ]);

        $response->assertCreated()->assertJsonPath('user_id', $user->id);
        $booking = Booking::query()->sole();
        $this->assertSame($user->id, $booking->user_id);
        $this->assertNotSame(999999, $booking->id);
        $this->assertNotSame('2000-01-01', $booking->created_at->toDateString());
    }

    public function test_returns_401_without_authentication(): void
    {
        $room = Room::factory()->create();

        $this->postJson('/api/bookings', $this->bookingData($room))
            ->assertUnauthorized();

        $this->assertDatabaseEmpty('bookings');
    }

    public function test_returns_422_for_missing_required_fields(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['room_id', 'title', 'starts_at', 'ends_at']);

        $this->assertDatabaseEmpty('bookings');
    }

    #[DataProvider('invalidFields')]
    public function test_returns_422_for_invalid_fields(string $field, mixed $value, string $message): void
    {
        $room = Room::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', [...$this->bookingData($room), $field => $value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field])
            ->assertJsonPath("errors.{$field}.0", $message);

        $this->assertDatabaseEmpty('bookings');
    }

    /**
     * @return array<string, array{string, mixed, string}>
     */
    public static function invalidFields(): array
    {
        $dateMessage = 'Укажите корректную дату ISO 8601 со смещением часового пояса.';

        return [
            'unknown room' => ['room_id', 999999, 'Переговорная не найдена.'],
            'room SQL injection' => ['room_id', '1 OR 1=1', 'Идентификатор переговорной должен быть целым числом.'],
            'room array' => ['room_id', [1], 'Идентификатор переговорной должен быть целым числом.'],
            'empty title' => ['title', '   ', 'Укажите название встречи.'],
            'title array' => ['title', ['Планёрка'], 'Название встречи должно быть строкой.'],
            'long title' => ['title', str_repeat('я', 256), 'Название встречи не должно превышать 255 символов.'],
            'start array' => ['starts_at', ['2026-11-02T10:00:00+03:00'], $dateMessage],
            'end numeric' => ['ends_at', 1793606400, $dateMessage],
            'start without offset' => ['starts_at', '2026-11-02T10:00:00', $dateMessage],
            'end without offset' => ['ends_at', '2026-11-02T11:00:00', $dateMessage],
            'relative date' => ['starts_at', 'tomorrow', $dateMessage],
            'nonexistent day' => ['starts_at', '2026-02-30T10:00:00+03:00', $dateMessage],
            'non-leap year' => ['ends_at', '2026-02-29T11:00:00+03:00', $dateMessage],
            'invalid hour' => ['starts_at', '2026-11-02T25:00:00+03:00', $dateMessage],
            'invalid offset' => ['ends_at', '2026-11-02T11:00:00+03:60', $dateMessage],
            'date SQL injection' => ['starts_at', "2026-11-02T10:00:00+03:00' OR '1'='1", $dateMessage],
        ];
    }

    #[DataProvider('overlappingIntervals')]
    public function test_rejects_overlaps_with_422(string $start, string $end): void
    {
        $room = Room::factory()->create();
        $existing = Booking::factory()->for($room)->create([
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', [
            ...$this->bookingData($room),
            'starts_at' => $start,
            'ends_at' => $end,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at'])
            ->assertJsonPath('errors.starts_at.0', 'Переговорная уже занята в выбранное время.');

        $this->assertDatabaseCount('bookings', 1);
        $this->assertModelExists($existing);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function overlappingIntervals(): array
    {
        return [
            'identical interval' => ['2026-11-02T10:00:00+03:00', '2026-11-02T11:00:00+03:00'],
            'overlap from left' => ['2026-11-02T09:45:00+03:00', '2026-11-02T10:15:00+03:00'],
            'overlap from right' => ['2026-11-02T10:45:00+03:00', '2026-11-02T11:15:00+03:00'],
            'new inside existing' => ['2026-11-02T10:15:00+03:00', '2026-11-02T10:45:00+03:00'],
            'existing inside new' => ['2026-11-02T09:45:00+03:00', '2026-11-02T11:15:00+03:00'],
            'same instant in UTC' => ['2026-11-02T07:00:00Z', '2026-11-02T08:00:00Z'],
            'same instant with another offset' => ['2026-11-02T12:00:00+05:00', '2026-11-02T13:00:00+05:00'],
        ];
    }

    #[DataProvider('adjacentIntervals')]
    public function test_allows_adjacent_bookings(string $start, string $end): void
    {
        $room = Room::factory()->create();
        Booking::factory()->for($room)->create([
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', [
            ...$this->bookingData($room),
            'starts_at' => $start,
            'ends_at' => $end,
        ])->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function adjacentIntervals(): array
    {
        return [
            'before' => ['2026-11-02T06:00:00Z', '2026-11-02T07:00:00Z'],
            'after' => ['2026-11-02T08:00:00Z', '2026-11-02T09:00:00Z'],
        ];
    }

    public function test_allows_same_user_to_book_another_room_at_the_same_time(): void
    {
        $user = User::factory()->create();
        Booking::factory()->for($user)->create([
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
        ]);
        $room = Room::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->bookingData($room))->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
        $this->assertDatabaseHas('bookings', ['room_id' => $room->id, 'user_id' => $user->id]);
    }

    #[DataProvider('validIntervals')]
    public function test_accepts_valid_intervals_and_stores_expected_utc(
        string $timezone,
        string $start,
        string $end,
        string $expectedStart,
        string $expectedEnd,
    ): void {
        $room = $this->createRoom($timezone);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/bookings', [
            ...$this->bookingData($room),
            'starts_at' => $start,
            'ends_at' => $end,
        ]);

        $response->assertCreated();
        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseHas('bookings', [
            'id' => $response->json('id'),
            'starts_at' => $expectedStart,
            'ends_at' => $expectedEnd,
        ]);
    }

    /**
     * @return array<string, array{string, string, string, string, string}>
     */
    public static function validIntervals(): array
    {
        return [
            'minimum duration and opening Moscow' => ['Europe/Moscow', '2026-11-02T09:00:00+03:00', '2026-11-02T09:15:00+03:00', '2026-11-02 06:00:00', '2026-11-02 06:15:00'],
            'maximum duration and closing Moscow' => ['Europe/Moscow', '2026-11-02T16:00:00+03:00', '2026-11-02T20:00:00+03:00', '2026-11-02 13:00:00', '2026-11-02 17:00:00'],
            'opening Novosibirsk in UTC' => ['Asia/Novosibirsk', '2026-11-02T02:00:00Z', '2026-11-02T02:15:00Z', '2026-11-02 02:00:00', '2026-11-02 02:15:00'],
            'closing Novosibirsk in Moscow offset' => ['Asia/Novosibirsk', '2026-11-02T15:45:00+03:00', '2026-11-02T16:00:00+03:00', '2026-11-02 12:45:00', '2026-11-02 13:00:00'],
            'negative offset' => ['Europe/Moscow', '2026-11-02T02:00:00-05:00', '2026-11-02T03:00:00-05:00', '2026-11-02 07:00:00', '2026-11-02 08:00:00'],
            'quarter hour in office timezone' => ['Europe/Moscow', '2026-11-02T12:50:00+05:50', '2026-11-02T13:50:00+05:50', '2026-11-02 07:00:00', '2026-11-02 08:00:00'],
            'different offsets for endpoints' => ['Europe/Moscow', '2026-11-02T10:00:00+03:00', '2026-11-02T13:00:00+05:00', '2026-11-02 07:00:00', '2026-11-02 08:00:00'],
            'client date differs from office date' => ['Asia/Novosibirsk', '2026-11-01T21:00:00-05:00', '2026-11-01T22:00:00-05:00', '2026-11-02 02:00:00', '2026-11-02 03:00:00'],
            'client midnight within office hours' => ['Europe/Moscow', '2026-11-02T23:45:00+14:00', '2026-11-03T00:00:00+14:00', '2026-11-02 09:45:00', '2026-11-02 10:00:00'],
            'without seconds' => ['Europe/Moscow', '2026-11-02T10:15+03:00', '2026-11-02T10:30+03:00', '2026-11-02 07:15:00', '2026-11-02 07:30:00'],
            'compact offset and zero fraction' => ['Europe/Moscow', '2026-11-02T10:00:00.000000+0300', '2026-11-02T11:00:00.000000+0300', '2026-11-02 07:00:00', '2026-11-02 08:00:00'],
            'valid leap day' => ['Europe/Moscow', '2028-02-29T10:00:00+03:00', '2028-02-29T11:00:00+03:00', '2028-02-29 07:00:00', '2028-02-29 08:00:00'],
        ];
    }

    #[DataProvider('invalidIntervals')]
    public function test_returns_422_for_invalid_booking_intervals(
        string $timezone,
        string $start,
        string $end,
        string $field,
        string $message,
    ): void {
        $room = $this->createRoom($timezone);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', [
            ...$this->bookingData($room),
            'starts_at' => $start,
            'ends_at' => $end,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([$field])
            ->assertJsonPath("errors.{$field}.0", $message);

        $this->assertDatabaseEmpty('bookings');
    }

    /**
     * @return array<string, array{string, string, string, string, string}>
     */
    public static function invalidIntervals(): array
    {
        $duration = 'Длительность встречи должна быть от 15 минут до 4 часов.';
        $order = 'Окончание встречи должно быть позже начала.';
        $step = 'Время должно соответствовать шагу 15 минут без секунд и долей секунды по времени офиса.';
        $hours = 'Встреча должна целиком проходить с 09:00 до 20:00 одного дня по времени офиса.';

        return [
            'zero duration' => ['Europe/Moscow', '2026-11-02T10:00:00+03:00', '2026-11-02T10:00:00+03:00', 'ends_at', $order],
            'negative duration' => ['Europe/Moscow', '2026-11-02T11:00:00+03:00', '2026-11-02T10:00:00+03:00', 'ends_at', $order],
            'negative duration hidden by offsets' => ['Europe/Moscow', '2026-11-02T10:00:00+03:00', '2026-11-02T11:00:00+05:00', 'ends_at', $order],
            'shorter than fifteen minutes' => ['Europe/Moscow', '2026-11-02T10:00:00+03:00', '2026-11-02T10:14:00+03:00', 'ends_at', $duration],
            'longer than four hours' => ['Europe/Moscow', '2026-11-02T10:00:00+03:00', '2026-11-02T14:15:00+03:00', 'ends_at', $duration],
            'start off grid' => ['Europe/Moscow', '2026-11-02T10:01:00+03:00', '2026-11-02T11:00:00+03:00', 'starts_at', $step],
            'end off grid' => ['Europe/Moscow', '2026-11-02T10:00:00+03:00', '2026-11-02T11:01:00+03:00', 'ends_at', $step],
            'nonzero start seconds' => ['Europe/Moscow', '2026-11-02T10:00:01+03:00', '2026-11-02T11:00:00+03:00', 'starts_at', $step],
            'nonzero end seconds' => ['Europe/Moscow', '2026-11-02T10:00:00+03:00', '2026-11-02T11:00:01+03:00', 'ends_at', $step],
            'start microseconds' => ['Europe/Moscow', '2026-11-02T10:00:00.000001+03:00', '2026-11-02T11:00:00+03:00', 'starts_at', $step],
            'end microseconds' => ['Europe/Moscow', '2026-11-02T10:00:00+03:00', '2026-11-02T11:00:00.000001+03:00', 'ends_at', $step],
            'grid valid only in client offset' => ['Europe/Moscow', '2026-11-02T10:00:00+03:10', '2026-11-02T11:00:00+03:10', 'starts_at', $step],
            'before opening Moscow' => ['Europe/Moscow', '2026-11-02T08:45:00+03:00', '2026-11-02T09:00:00+03:00', 'starts_at', $hours],
            'after closing Moscow' => ['Europe/Moscow', '2026-11-02T19:45:00+03:00', '2026-11-02T20:15:00+03:00', 'ends_at', $hours],
            'start at closing' => ['Europe/Moscow', '2026-11-02T20:00:00+03:00', '2026-11-02T20:15:00+03:00', 'ends_at', $hours],
            'before opening Novosibirsk' => ['Asia/Novosibirsk', '2026-11-02T08:45:00+07:00', '2026-11-02T09:00:00+07:00', 'starts_at', $hours],
            'after closing Novosibirsk' => ['Asia/Novosibirsk', '2026-11-02T19:45:00+07:00', '2026-11-02T20:15:00+07:00', 'ends_at', $hours],
            'client working hours but office closed' => ['Asia/Novosibirsk', '2026-11-02T17:00:00+03:00', '2026-11-02T18:00:00+03:00', 'ends_at', $hours],
            'crossing office midnight' => ['Europe/Moscow', '2026-11-02T23:00:00+03:00', '2026-11-03T01:00:00+03:00', 'ends_at', $hours],
        ];
    }

    public function test_allows_past_weekend_dates(): void
    {
        $this->travelTo('2026-11-07 12:00:00');
        $room = Room::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/bookings', [
            ...$this->bookingData($room),
            'starts_at' => '2026-11-01T10:00:00+03:00',
            'ends_at' => '2026-11-01T11:00:00+03:00',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('bookings', [
            'id' => $response->json('id'),
            'starts_at' => '2026-11-01 07:00:00',
        ]);
    }

    private function createRoom(string $timezone): Room
    {
        $office = $timezone === 'Asia/Novosibirsk'
            ? Office::factory()->novosibirsk()
            : Office::factory();

        return Room::factory()->for($office)->create();
    }

    /**
     * @return array{room_id: int, title: string, starts_at: string, ends_at: string}
     */
    private function bookingData(Room $room): array
    {
        return [
            'room_id' => $room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ];
    }
}

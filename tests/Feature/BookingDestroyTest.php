<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingDestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_can_cancel_only_the_requested_booking(): void
    {
        $author = User::factory()->create();
        $booking = Booking::factory()->for($author)->create();
        $anotherOwnBooking = Booking::factory()->for($author)->create();
        $otherUserBooking = Booking::factory()->create();
        Sanctum::actingAs($author);

        $this->deleteJson("/api/bookings/{$booking->id}")->assertNoContent();

        $this->assertModelMissing($booking);
        $this->assertModelExists($anotherOwnBooking);
        $this->assertModelExists($otherUserBooking);
        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_returns_403_when_another_user_cancels_a_booking(): void
    {
        $booking = Booking::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson("/api/bookings/{$booking->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'Отменить бронь может только её автор.');

        $this->assertModelExists($booking);
        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_returns_403_when_another_user_supplies_the_authors_id(): void
    {
        $booking = Booking::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson("/api/bookings/{$booking->id}", [
            'user_id' => $booking->user_id,
        ])->assertForbidden();

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'user_id' => $booking->user_id,
        ]);
        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_returns_401_without_authentication(): void
    {
        $booking = Booking::factory()->create();

        $this->deleteJson("/api/bookings/{$booking->id}")->assertUnauthorized();

        $this->assertModelExists($booking);
        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_returns_404_for_a_missing_booking(): void
    {
        $author = User::factory()->create();
        $booking = Booking::factory()->for($author)->create();
        Sanctum::actingAs($author);

        $this->deleteJson('/api/bookings/999999')->assertNotFound();

        $this->assertModelExists($booking);
        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_cancelled_slot_can_be_booked_again_by_another_user(): void
    {
        $author = User::factory()->create();
        $room = Room::factory()->create();
        $booking = Booking::factory()->for($author)->for($room)->create([
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
        ]);
        Sanctum::actingAs($author);

        $this->deleteJson("/api/bookings/{$booking->id}")->assertNoContent();

        $otherUser = User::factory()->create();
        Sanctum::actingAs($otherUser);

        $response = $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Встреча в освободившейся переговорной',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ]);

        $response->assertCreated();
        $this->assertModelMissing($booking);
        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseHas('bookings', [
            'id' => $response->json('id'),
            'user_id' => $otherUser->id,
            'room_id' => $room->id,
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
        ]);
    }
}

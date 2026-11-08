<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    public function store(StoreBookingRequest $request): Booking
    {
        $validated = $request->validated();
        $startsAt = CarbonImmutable::parse($validated['starts_at'])->utc();
        $endsAt = CarbonImmutable::parse($validated['ends_at'])->utc();

        $conflict = Booking::query()
            ->where('room_id', $validated['room_id'])
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'starts_at' => 'Переговорная уже занята в выбранное время.',
            ]);
        }

        $booking = $request->user()->bookings()->create([
            'room_id' => $validated['room_id'],
            'title' => $validated['title'],
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);

        Log::channel('bookings')->info('Booking created', ['booking_id' => $booking->id]);

        return $booking;
    }

    public function destroy($id)
    {
        Booking::findOrFail($id)->delete();

        return response()->noContent();
    }

    public function today(Room $room)
    {
        return $room->bookings()
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at.' – '.$b->ends_at,
                'author' => $b->user->name,
            ]);
    }
}

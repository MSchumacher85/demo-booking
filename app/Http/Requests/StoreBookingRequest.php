<?php

namespace App\Http\Requests;

use App\Models\Room;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $dateRules = [
            'bail',
            'required',
            'string',
            'regex:/\A\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d(?:\.\d{1,6})?)?(?:Z|[+-](?:[01]\d|2[0-3]):?[0-5]\d)\z/',
            'date',
        ];

        return [
            'room_id' => ['bail', 'required', 'integer', 'exists:rooms,id'],
            'title' => ['bail', 'required', 'string', 'max:255'],
            'starts_at' => $dateRules,
            'ends_at' => $dateRules,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $dateMessage = 'Укажите корректную дату ISO 8601 со смещением часового пояса.';

        return [
            'room_id.required' => 'Выберите переговорную.',
            'room_id.integer' => 'Идентификатор переговорной должен быть целым числом.',
            'room_id.exists' => 'Переговорная не найдена.',
            'title.required' => 'Укажите название встречи.',
            'title.string' => 'Название встречи должно быть строкой.',
            'title.max' => 'Название встречи не должно превышать 255 символов.',
            'starts_at.required' => 'Укажите время начала встречи.',
            'starts_at.string' => $dateMessage,
            'starts_at.regex' => $dateMessage,
            'starts_at.date' => $dateMessage,
            'ends_at.required' => 'Укажите время окончания встречи.',
            'ends_at.string' => $dateMessage,
            'ends_at.regex' => $dateMessage,
            'ends_at.date' => $dateMessage,
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $startsAt = CarbonImmutable::parse($this->input('starts_at'));
                $endsAt = CarbonImmutable::parse($this->input('ends_at'));

                if ($endsAt->lessThanOrEqualTo($startsAt)) {
                    $validator->errors()->add('ends_at', 'Окончание встречи должно быть позже начала.');

                    return;
                }

                $durationInSeconds = $startsAt->diffInSeconds($endsAt);

                if ($durationInSeconds < 900 || $durationInSeconds > 14400) {
                    $validator->errors()->add('ends_at', 'Длительность встречи должна быть от 15 минут до 4 часов.');

                    return;
                }

                $room = Room::query()->with('office')->find($this->integer('room_id'));

                if ($room === null) {
                    $validator->errors()->add('room_id', 'Переговорная не найдена.');

                    return;
                }

                $localStart = $startsAt->setTimezone($room->office->timezone);
                $localEnd = $endsAt->setTimezone($room->office->timezone);

                foreach (['starts_at' => $localStart, 'ends_at' => $localEnd] as $field => $date) {
                    if ($date->minute % 15 !== 0 || $date->second !== 0 || $date->microsecond !== 0) {
                        $validator->errors()->add(
                            $field,
                            'Время должно соответствовать шагу 15 минут без секунд и долей секунды по времени офиса.',
                        );
                    }
                }

                $workingHoursMessage = 'Встреча должна целиком проходить с 09:00 до 20:00 одного дня по времени офиса.';

                if ($localStart->lessThan($localStart->setTime(9, 0))) {
                    $validator->errors()->add('starts_at', $workingHoursMessage);
                }

                if ($localEnd->greaterThan($localStart->setTime(20, 0))) {
                    $validator->errors()->add('ends_at', $workingHoursMessage);
                }
            },
        ];
    }
}

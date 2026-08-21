<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventDetail extends Model
{
    protected $fillable = [
        'event_name',
        'event_description',
        'event_date',
        'event_time',
        'venue',
        'venue_address',
        'google_maps_url',
        'dress_code',
        'parking_information',
        'accommodation_information',
        'contact_number',
        'contact_person',
        'event_program',
        'rsvp_link',
        'rsvp_information',
        'gift_information',
        'important_instructions',
        'other_information',
    ];

    protected $casts = [
        'event_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function singleton(): self
    {
        $detail = static::query()->find(1);

        if ($detail === null) {
            $detail = new static;
            $detail->forceFill(['id' => 1])->save();
        }

        return $detail->refresh();
    }
}

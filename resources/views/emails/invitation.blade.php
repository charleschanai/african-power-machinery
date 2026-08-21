<x-mail::message>
# Hi {{ $guestName }},

You have been officially invited!

@if($eventName)
**Event:** {{ $eventName }}
@endif

@if($eventDate)
**Date:** {{ $eventDate }}
@endif

@if($eventTime)
**Time:** {{ $eventTime }}
@endif

@if($venue)
**Venue:** {{ $venue }}
@endif

@if($tableNumber)
**Table:** {{ $tableNumber }}
@endif

@if($seatNumber)
**Seat:** {{ $seatNumber }}
@endif

**Card Number:** {{ $cardNumber }}
**Card Type:** {{ $cardType }} ({{ $allowedPeople }} {{ Str::plural('person', $allowedPeople) }} allowed)

@if($specialMessage)
> {{ $specialMessage }}
@endif

<x-mail::button :url="$cardUrl">
View Your E-Card
</x-mail::button>

<p style="text-align: center; color: #94a3b8; font-size: 12px;">
    Or copy this link: {{ $cardUrl }}
</p>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>

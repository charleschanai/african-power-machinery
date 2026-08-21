<?php
namespace App\Mail;

use App\Models\Card;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Card $card,
        public string $cardUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        $eventName = $this->card->event_name ?? config('app.name');

        return new Envelope(
            subject: "You're Invited! {$eventName}",
        );
    }

    public function content(): Content
    {
        $eventDate = null;
        if ($this->card->event_date) {
            $eventDate = \Carbon\Carbon::parse($this->card->event_date)->format('l, F j, Y');
        }

        $eventTime = $this->card->event_time;
        if ($eventTime && str_contains($eventTime, ':')) {
            try {
                $eventTime = \Carbon\Carbon::parse($eventTime)->format('g:i A');
            } catch (\Throwable $e) {
                $eventTime = $this->card->event_time;
            }
        }

        return new Content(
            markdown: 'emails.invitation',
            with: [
                'guestName' => $this->card->guest?->full_name ?? 'Guest',
                'cardNumber' => $this->card->card_number,
                'eventName' => $this->card->event_name,
                'eventDate' => $eventDate,
                'eventTime' => $eventTime,
                'venue' => $this->card->venue,
                'tableNumber' => $this->card->table_number,
                'seatNumber' => $this->card->seat_number,
                'specialMessage' => $this->card->special_message,
                'cardUrl' => $this->cardUrl,
                'cardType' => $this->card->card_type->label(),
                'allowedPeople' => $this->card->allowed_people,
            ],
        );
    }
}

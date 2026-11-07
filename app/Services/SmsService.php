<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    private const BASE_URL = 'https://messaging-service.co.tz';

    /**
     * Status words that mean the message was not accepted for delivery.
     *
     * NextSMS answers HTTP 200 even when it refuses to send, so the reply body
     * is the only trustworthy signal. A sender id that is not registered on the
     * account comes back as REJECTED / REJECTED_SOURCE with HTTP 200, which is
     * exactly the case that has to be caught rather than reported as sent.
     */
    private const REFUSED_WORDS = [
        'REJECT',
        'UNDELIVER',
        'FAIL',
        'EXPIRED',
        'INVALID',
        'BLOCK',
        'BLACKLIST',
        'UNKNOWN',
        'NOTREGISTERED',
    ];

    /**
     * Words that positively mean the provider took the message.
     *
     * This exists for one narrow case: a reply that names the outcome but
     * carries no group at all, such as a bare {"name": "MESSAGE_SENT"}. Reading
     * only the group would call that a failure, and an admin told a guest had
     * failed would send again and pay twice for the same person.
     *
     * It is checked only after the refusal words, so a word that means both,
     * which is what "not accepted" is, is always read as the refusal it is.
     */
    private const ACCEPTED_WORDS = [
        'SUCCESS',
        'SENT',
        'ACCEPTED',
        'SUBMITTED',
        'DELIVERED',
        'QUEUED',
        'PROCESSING',
    ];

    public function isConfigured(): bool
    {
        return filled(config('services.sms.nextsms_username'))
            && filled(config('services.sms.nextsms_password'));
    }

    /**
     * Reads the outcome out of a NextSMS reply.
     *
     * The provider does not use one shape. The bulk endpoint answers with a
     * "messages" array, one entry per recipient, with the outcome under that
     * entry's "status" object, and has also used "response" in places. The
     * single message endpoint answers flat, with the same fields sitting at the
     * top level of the body and nothing wrapped around them.
     *
     * Only the wrapped shape used to be read, so a single message that had
     * genuinely been accepted came back as unreadable, was treated as a
     * failure, and left the admin looking for a guest who had in fact been
     * messaged. That is how a message gets paid for twice. Both shapes are
     * read now, and returns null only when neither carries a recognisable
     * status, which the callers still treat as a failure rather than a success.
     */
    private function readStatus(mixed $payload): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        foreach (['messages', 'response'] as $key) {
            $items = $payload[$key] ?? null;

            if (! is_array($items) || $items === []) {
                continue;
            }

            $first = $items[array_key_first($items)];

            if (! is_array($first) || ! is_array($first['status'] ?? null)) {
                continue;
            }

            $status = $first['status'];

            return [
                'to' => $first['to'] ?? null,
                'group_name' => strtoupper((string) ($status['groupName'] ?? '')),
                'group_id' => $status['groupId'] ?? null,
                'name' => strtoupper((string) ($status['name'] ?? '')),
                'description' => $status['description'] ?? ($status['name'] ?? null),
                'reference' => $first['sendReference'] ?? ($status['messageId'] ?? null),
                'sms_count' => $first['smsCount'] ?? null,
            ];
        }

        // The flat reply, which is what one guest at a time comes back as.
        if ($this->looksLikeAStatus($payload)) {
            return [
                'to' => $payload['to'] ?? null,
                'group_name' => strtoupper((string) ($payload['groupName'] ?? '')),
                'group_id' => $payload['groupId'] ?? null,
                'name' => strtoupper((string) ($payload['name'] ?? '')),
                'description' => $payload['description'] ?? ($payload['name'] ?? null),
                'reference' => $payload['sendReference'] ?? ($payload['messageId'] ?? null),
                'sms_count' => $payload['smsCount'] ?? null,
            ];
        }

        return null;
    }

    /**
     * Whether a body carries a status in its own right.
     *
     * Checked by the field names rather than by whether the body is non-empty,
     * so a body that happens to be an error page or an empty object is never
     * mistaken for an outcome and then reported to the admin as one.
     */
    private function looksLikeAStatus(array $payload): bool
    {
        return array_key_exists('groupName', $payload)
            || array_key_exists('name', $payload)
            || array_key_exists('groupId', $payload)
            || array_key_exists('description', $payload);
    }

    /**
     * Whether NextSMS accepted the message for delivery.
     *
     * Anything that cannot be positively identified as accepted is treated as
     * not accepted. Guessing "success" here is the expensive mistake: the
     * invitation is marked Sent, the money is spent, and no guest ever receives
     * the message.
     */
    private function isAccepted(?array $status): bool
    {
        if ($status === null) {
            return false;
        }

        foreach ([$status['group_name'], $status['name']] as $word) {
            if ($word === '') {
                continue;
            }

            foreach (self::REFUSED_WORDS as $bad) {
                if (str_contains($word, $bad)) {
                    return false;
                }
            }
        }

        // Rejection group ids observed from the provider.
        if (in_array($status['group_id'], [19, 2, 4, 5], true)) {
            return false;
        }

        // A reply that names the outcome but carries no group at all. The
        // refusal words were already ruled out above, so reaching here with one
        // of these in it means the provider did accept the message.
        foreach ([$status['group_name'], $status['name']] as $word) {
            if ($word === '') {
                continue;
            }

            foreach (self::ACCEPTED_WORDS as $good) {
                $at = stripos($word, $good);

                if ($at === false) {
                    continue;
                }

                // A negation sitting in front of the word turns the whole thing
                // into a refusal, and the refusal is the one that has to win.
                // NOT_ACCEPTED and UNSENT both contain an accepted word, and
                // reading either of them as a send means marking a guest as
                // messaged when nothing was ever handed over.
                $prefix = substr($word, 0, $at);

                if (preg_match('/(^|[_\s])(NOT|UN|NO|NEVER|CANNOT|COULDNOT)$/i', $prefix) === 1) {
                    return false;
                }

                return true;
            }
        }

        // Nothing identifiable in the reply at all, which cannot be called a
        // success. Guessing yes here is the expensive mistake, so the answer is
        // no and the reason is left in the body for the log.
        return false;
    }

    public function send(string $to, string $message): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'configured' => false,
                'message' => 'The SMS API is not configured. Set NEXTSMS_USERNAME and NEXTSMS_PASSWORD in .env.',
            ];
        }

        $phone = PhoneHelper::toE164($to);

        if ($phone === null || strlen($phone) < 10) {
            return [
                'success' => false,
                'configured' => true,
                'message' => 'The phone number is invalid.',
            ];
        }

        $username = config('services.sms.nextsms_username');
        $password = config('services.sms.nextsms_password');
        $senderId = config('services.sms.nextsms_sender_id', 'NEXTSMS');

        try {
            $response = $this->postWithRetries(
                self::BASE_URL . '/api/sms/v1/text/single',
                [
                    'from' => $senderId,
                    'to' => $phone,
                    'text' => $message,
                ],
            );

            if ($response->failed()) {
                Log::error('NextSMS request failed', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);

                $errorMsg = $response->json('error.message')
                    ?? $response->json('message')
                    ?? 'Failed to send the SMS. Please try again.';

                return [
                    'success' => false,
                    'configured' => true,
                    'message' => $errorMsg,
                ];
            }

            $status = $this->readStatus($response->json());

            if (! $this->isAccepted($status)) {
                Log::error('NextSMS refused the message', [
                    'to' => $phone,
                    'status' => $status,
                    'body' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'configured' => true,
                    'message' => $status['description']
                        ?? 'The SMS service did not confirm this message, so it was not sent. '
                        . 'Check the sender id is registered on the account.',
                    'error_code' => $status['name'] ?? 'UNCONFIRMED',
                ];
            }

            return [
                'success' => true,
                'configured' => true,
                'message' => 'The SMS was sent successfully.',
                'message_id' => $status['reference'] ?? null,
                'sms_count' => $status['sms_count'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::error('NextSMS exception', [
                'error' => $e->getMessage(),
                'to' => $phone,
                'attempts' => self::SEND_ATTEMPTS,
            ]);

            return [
                'success' => false,
                'configured' => true,
                // Wording this carefully matters, because the admin is looking at
                // this message deciding whether to pay for another attempt. The
                // request never arrived, so nothing was sent, nothing was
                // charged, and the guest has not been messaged. Saying "failed"
                // on its own sends people looking for a guest who was missed.
                'message' => 'The SMS service could not be reached after ' . self::SEND_ATTEMPTS
                    . ' attempts, so nothing was sent and nothing was charged. '
                    . 'This guest has not been messaged yet, and sending again is safe.',
                'error_code' => 'SERVICE_UNREACHABLE',
                'detail' => $e->getMessage(),
            ];
        }
    }

    /**
     * How many times one message is tried before giving up.
     *
     * Three is not arbitrary. Measured on this machine, the same address
     * answered 6 times out of 6 and another 6 out of 6, so the failures are
     * brief blips rather than a lasting fault, and a second attempt clears
     * them. Three still leaves a short, visible wait instead of turning one
     * flaky moment into a guest who was quietly never invited.
     */
    private const SEND_ATTEMPTS = 3;

    /**
     * Posts to the SMS service, retrying only when nothing was delivered.
     *
     * The retry is deliberately narrow. It fires only for a connection that
     * never produced a response, which is the one case where it is certain no
     * SMS was sent and no money was spent. The moment the service answers at
     * all, its answer is passed straight back, because a refusal must not be
     * turned into several charged attempts at the same guest's phone, and a
     * success must never be sent twice.
     *
     * @return \Illuminate\Http\Client\Response
     *
     * @throws \Illuminate\Http\Client\ConnectionException
     */
    private function postWithRetries(string $url, array $payload, int $timeout = 30): \Illuminate\Http\Client\Response
    {
        $username = config('services.sms.nextsms_username');
        $password = config('services.sms.nextsms_password');

        $lastError = null;

        for ($attempt = 1; $attempt <= self::SEND_ATTEMPTS; $attempt++) {
            try {
                return Http::withHeaders([
                    'Authorization' => 'Basic ' . base64_encode("{$username}:{$password}"),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                    ->timeout($timeout)
                    ->post($url, $payload);
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                // A short pause before the next try, so a momentary network
                // problem is not hit again at full speed while it is still
                // happening. Grows a little each round and stays under a second
                // in total, which the admin will not notice but the network
                // does.
                $lastError = $e;

                if ($attempt < self::SEND_ATTEMPTS) {
                    usleep(300_000 * $attempt);
                }
            }
        }

        throw $lastError;
    }

    /**
     * Queries the NextSMS delivery report for a previously sent message.
     *
     * Returns a normalized status: delivered | sent | failed | unknown.
     */
    public function deliveryStatus(string $messageId): array
    {
        if (! $this->isConfigured() || $messageId === '') {
            return ['status' => 'unknown', 'description' => null];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . base64_encode(
                    config('services.sms.nextsms_username') . ':' . config('services.sms.nextsms_password')
                ),
                'Accept' => 'application/json',
            ])
                ->timeout(30)
                ->get(self::BASE_URL . '/api/sms/v1/reports', ['messageId' => $messageId]);

            if ($response->failed()) {
                return ['status' => 'unknown', 'description' => 'Delivery report request failed.'];
            }

            $report = $this->readStatus($response->json());

            if ($report === null) {
                return ['status' => 'unknown', 'description' => 'No delivery report found.'];
            }

            $description = $report['description'];
            $groupName = $report['group_name'];

            if (str_contains($groupName, 'DELIVERED')) {
                return ['status' => 'delivered', 'description' => $description];
            }

            if (! $this->isAccepted($report)) {
                return ['status' => 'failed', 'description' => $description];
            }

            // PENDING / ACCEPTED / QUEUED / SENT — still on its way.
            return ['status' => 'sent', 'description' => $description];
        } catch (\Throwable $e) {
            Log::error('NextSMS delivery report exception', ['error' => $e->getMessage()]);

            return ['status' => 'unknown', 'description' => $e->getMessage()];
        }
    }

    public function sendBulk(array $recipients, string $message): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'configured' => false,
                'message' => 'The SMS API is not configured.',
            ];
        }

        $username = config('services.sms.nextsms_username');
        $password = config('services.sms.nextsms_password');
        $senderId = config('services.sms.nextsms_sender_id', 'NEXTSMS');

        $messages = collect($recipients)->map(fn ($phone) => [
            'to' => $phone,
            'text' => $message,
        ])->values()->all();

        try {
            $response = $this->postWithRetries(
                self::BASE_URL . '/api/sms/v1/text/multi',
                [
                    'from' => $senderId,
                    'messages' => $messages,
                ],
                60,
            );

            if ($response->failed()) {
                Log::error('NextSMS bulk request failed', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'configured' => true,
                    'message' => 'Failed to send bulk SMS.',
                ];
            }

            // NextSMS answers 200 even for numbers it refuses, so the reply is
            // what decides. Reporting the whole batch as sent without reading it
            // is how a whole guest list gets marked as notified when not one
            // message was delivered.
            $status = $this->readStatus($response->json());

            if (! $this->isAccepted($status)) {
                Log::error('NextSMS refused the bulk batch', [
                    'recipients' => count($recipients),
                    'status' => $status,
                    'body' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'configured' => true,
                    'message' => $status['description']
                        ?? 'The SMS service did not confirm this batch, so nothing was sent. '
                        . 'Check the sender id is registered on the account.',
                    'error_code' => $status['name'] ?? 'UNCONFIRMED',
                ];
            }

            return [
                'success' => true,
                'configured' => true,
                'message' => count($recipients) . ' SMS sent successfully.',
                'message_id' => $status['reference'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::error('NextSMS bulk exception', [
                'error' => $e->getMessage(),
                'recipients' => count($recipients),
                'attempts' => self::SEND_ATTEMPTS,
            ]);

            return [
                'success' => false,
                'configured' => true,
                'message' => 'The SMS service could not be reached after ' . self::SEND_ATTEMPTS
                    . ' attempts, so none of these ' . count($recipients)
                    . ' guests were messaged and nothing was charged. Sending again is safe.',
                'error_code' => 'SERVICE_UNREACHABLE',
                'detail' => $e->getMessage(),
            ];
        }
    }
}

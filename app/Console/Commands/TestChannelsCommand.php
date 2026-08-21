<?php

namespace App\Console\Commands;

use App\Services\PhoneHelper;
use App\Services\SmsService;
use App\Services\WhatsAppService;
use Illuminate\Console\Command;

class TestChannelsCommand extends Command
{
    protected $signature = 'ecard:test-channels
        {phone? : Namba ya simu kwa format yoyote (k.m. 0614063715)}
        {--whatsapp : Tuma WhatsApp pekee}
        {--sms : Tuma SMS pekee}
        {--sms-env= : Override sms env (sandbox|production)}';

    protected $description = 'Tuma ujumbe wa mwaliko wa test kwa namba fulani kupitia WhatsApp na/au SMS.';

    public function handle(WhatsAppService $whatsApp, SmsService $sms): int
    {
        $phone = $this->argument('phone') ?? '0614063715';

        $e164 = PhoneHelper::toE164($phone);

        if ($e164 === null || strlen($e164) < 10) {
            $this->error("Namba ya simu si sahihi: {$phone}");

            return self::FAILURE;
        }

        $this->info('Namba iliyorekebishwa (E.164): +'.$e164);

        $sendWhatsApp = $this->option('whatsapp') || (! $this->option('whatsapp') && ! $this->option('sms'));
        $sendSms = $this->option('sms') || (! $this->option('whatsapp') && ! $this->option('sms'));

        if ($this->option('sms-env')) {
            config(['services.sms.at_env' => $this->option('sms-env')]);
        }

        $body = $this->sampleMessage();

        if ($sendWhatsApp) {
            $this->line('');
            $this->info('>>> Kutuma WhatsApp...');

            $result = $whatsApp->sendText($e164, $body);

            $this->line('Configured: '.($result['configured'] ? 'ndiyo' : 'hapana'));
            $this->line($result['message']);

            if (! empty($result['wa_message_id'])) {
                $this->info('Message ID: '.$result['wa_message_id']);
            }

            if (! empty($result['wa_link'])) {
                $this->warn('Wa link: '.$result['wa_link']);
            }

            if (! $result['success']) {
                $this->warn('Sababu ya kukosa: hakikisha WHATSAPP_API_TOKEN na WHATSAPP_PHONE_NUMBER_ID zimewekwa kwenye .env.');
            }
        }

        if ($sendSms) {
            $this->line('');
            $this->info('>>> Kutuma SMS (env: '.config('services.sms.at_env').')...');

            $result = $sms->send($e164, $body);

            $this->line('Configured: '.($result['configured'] ? 'ndiyo' : 'hapana'));
            $this->line($result['message']);

            if (! $result['success']) {
                $this->warn('Sababu ya kukosa: hakikisha AFRICASTALKING_USERNAME na AFRICASTALKING_API_KEY zimewekwa, na SMS iko kwenye production kwa utoaji halisi.');
            }
        }

        $this->line('');

        return self::SUCCESS;
    }

    private function sampleMessage(): string
    {
        return implode(PHP_EOL, [
            'Habari Mgeni,',
            '',
            'Umealikwa kwenye sherehe.',
            'Event: Sherehe ya Mfano',
            'Date: 01/01/2027',
            'Time: 18:00',
            'Venue: Ukumbi wa Mfano',
            '',
            'Aina ya kadi: Single',
            'Namba ya kadi: EC-000001',
            '',
            'Fungua E-Kadi yako kupitia kiungo na uiwasilishe mlangoni.',
            'https://wa.me/255614063715?text=E-Card%20Test',
            '',
            'Asante.',
        ]);
    }
}

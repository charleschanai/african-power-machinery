<?php

namespace Tests\Unit;

use App\Services\InvitationReportPdf;
use Tests\TestCase;

class InvitationReportPdfTest extends TestCase
{
    public function test_it_generates_a_downloadable_pdf_report(): void
    {
        $pdf = app(InvitationReportPdf::class)->generate($this->reportData([
            $this->row(1, 'Asha Guest', 'delivered'),
        ]));

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringEndsWith('%%EOF', $pdf);
        $this->assertStringContainsString('Asha Guest', $pdf);
        $this->assertStringContainsString('DELIVERED', $pdf);
    }

    public function test_it_continues_large_reports_on_multiple_pages(): void
    {
        $rows = [];
        for ($id = 1; $id <= 40; $id++) {
            $rows[] = $this->row($id, 'Guest ' . $id, 'pending');
        }

        $pdf = app(InvitationReportPdf::class)->generate($this->reportData($rows));
        preg_match_all('/\/Type \/Page /', $pdf, $matches);

        $this->assertGreaterThan(1, count($matches[0]));
        $this->assertStringContainsString('Guest 40', $pdf);
    }

    private function row(int $id, string $name, string $status): array
    {
        return [
            'id' => $id,
            'guest_id' => $id,
            'guest_name' => $name,
            'phone' => '255700000000',
            'card_number' => 'CARD-' . $id,
            'card_type' => 'single',
            'sms_status' => $status,
            'event_name' => 'Test Event',
            'event_date' => '2026-11-12',
            'venue' => 'Test Venue',
        ];
    }

    private function reportData(array $rows): array
    {
        return [
            'rows' => $rows,
            'generated_at' => '2026-11-12 12:00:00',
            'campaign_reference' => 'INV-RPT-TEST',
            'filters' => ['event' => 'Test Event'],
        ];
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvitationReportDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authorized_admin_can_download_the_pdf_directly(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/invitations/report/download?kind=all');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('Content-Disposition');
        $this->assertStringStartsWith('%PDF-1.4', $response->getContent());
        $this->assertStringEndsWith('%%EOF', $response->getContent());
    }
}

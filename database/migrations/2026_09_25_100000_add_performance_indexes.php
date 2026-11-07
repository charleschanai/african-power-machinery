<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance indexes.
 *
 * Every index here maps to a query that already exists in the application.
 * See the comments for the exact call site each index serves.
 *
 * Written to run safely on both MySQL/MariaDB and SQLite. On SQLite a
 * separate migration cannot always use ->after(), so index creation is done
 * through a driver-aware helper instead of the Schema builder's column
 * positioning.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------
        // guests
        // ------------------------------------------------------------------
        // Guest::findByPhone() and the E.164 normalisation in Guest::booted()
        // both hit phone_e164 on every WhatsApp/SMS send.
        $this->addIndex('guests', 'guests_phone_e164_idx', ['phone_e164']);

        // GuestController::index()/export() search with LIKE across
        // full_name, phone and email, ordered by id.
        $this->addIndex('guests', 'guests_full_name_id_idx', ['full_name', 'id']);
        $this->addIndex('guests', 'guests_email_idx', ['email']);

        // ------------------------------------------------------------------
        // cards
        // ------------------------------------------------------------------
        // CardController::index() filters status + card_type then orders by id.
        $this->addIndex('cards', 'cards_status_type_idx', ['status', 'card_type']);
        // Guest::cards() eager load + "cards for this guest, newest first".
        $this->addIndex('cards', 'cards_guest_status_idx', ['guest_id', 'status']);
        // InvitationCampaignService::eligibleCards() and the weekly reminder
        // sweep both filter on status and look at expires_at.
        $this->addIndex('cards', 'cards_expires_at_idx', ['expires_at']);
        // EventAssistant::handleCard() orders a guest's cards by id.
        $this->addIndex('cards', 'cards_guest_id_desc_idx', ['guest_id', 'id']);

        // ------------------------------------------------------------------
        // entry_logs
        // ------------------------------------------------------------------
        // EntryLogController::index()/export(): filter by result, order by
        // scanned_at desc. A composite index serves the filter and the sort.
        $this->addIndex('entry_logs', 'entry_logs_result_scanned_idx', ['result', 'scanned_at']);
        $this->addIndex('entry_logs', 'entry_logs_scanned_desc_idx', ['scanned_at', 'id']);
        // CardShow / GuestShow entry history for one card or guest.
        $this->addIndex('entry_logs', 'entry_logs_card_scanned_idx', ['card_id', 'scanned_at']);
        $this->addIndex('entry_logs', 'entry_logs_guest_idx', ['guest_id']);
        $this->addIndex('entry_logs', 'entry_logs_processor_idx', ['processed_by']);
        // WeeklyReminderService dedupe looks at (year, week_number, status).
        $this->addIndex('entry_logs', 'entry_logs_method_idx', ['verification_method']);

        // ------------------------------------------------------------------
        // invitation_sends
        // ------------------------------------------------------------------
        // syncSmsDeliveryStatus(): channel + status + sent_at, oldest first.
        $this->addIndex('invitation_sends', 'inv_sends_channel_status_idx', ['channel', 'status']);
        // ProcessScheduledSms picks up queued rows that are due.
        $this->addIndex('invitation_sends', 'inv_sends_status_sched_idx', ['status', 'scheduled_at']);
        $this->addIndex('invitation_sends', 'inv_sends_scheduled_idx', ['scheduled_at']);

        // ------------------------------------------------------------------
        // whatsapp
        // ------------------------------------------------------------------
        // WhatsAppAdminController::conversations() filters status, orders by
        // last_message_at desc.
        $this->addIndex('whatsapp_conversations', 'wa_conv_status_last_idx', ['status', 'last_message_at']);
        $this->addIndex('whatsapp_conversations', 'wa_conv_guest_idx', ['guest_id']);
        $this->addIndex('whatsapp_messages', 'wa_msg_conv_desc_idx', ['conversation_id', 'id']);
        $this->addIndex('whatsapp_faqs', 'wa_faqs_status_idx', ['status']);

        // ------------------------------------------------------------------
        // weekly reminders + audit
        // ------------------------------------------------------------------
        // WeeklyReminderService::alreadySentThisWeek() and getWeekSummary().
        $this->addIndex('weekly_reminder_logs', 'wrl_year_week_status_idx', ['year', 'week_number', 'status']);
        // Compound, not a bare card_id: the foreign key already guarantees an
        // index on that column, MySQL refuses to drop an index a constraint
        // depends on, and a duplicate here would make down() impossible.
        $this->addIndex('weekly_reminder_logs', 'wrl_card_status_idx', ['card_id', 'status']);
        // Audit trail screens filter by event within a time window.
        $this->addIndex('audit_logs', 'audit_logs_event_created_idx', ['event', 'created_at']);
        $this->addIndex('audit_logs', 'audit_logs_user_created_idx', ['user_id', 'created_at']);

        // ------------------------------------------------------------------
        // users
        // ------------------------------------------------------------------
        // /api/v1/me and the admin user list.
        $this->addIndex('users', 'users_role_idx', ['role']);
    }

    public function down(): void
    {
        foreach ([
            'users_role_idx' => 'users',
            'audit_logs_user_created_idx' => 'audit_logs',
            'audit_logs_event_created_idx' => 'audit_logs',
            'wrl_card_status_idx' => 'weekly_reminder_logs',
            'wrl_year_week_status_idx' => 'weekly_reminder_logs',
            'wa_faqs_status_idx' => 'whatsapp_faqs',
            'wa_msg_conv_desc_idx' => 'whatsapp_messages',
            'wa_conv_guest_idx' => 'whatsapp_conversations',
            'wa_conv_status_last_idx' => 'whatsapp_conversations',
            'inv_sends_scheduled_idx' => 'invitation_sends',
            'inv_sends_status_sched_idx' => 'invitation_sends',
            'inv_sends_channel_status_idx' => 'invitation_sends',
            'entry_logs_method_idx' => 'entry_logs',
            'entry_logs_processor_idx' => 'entry_logs',
            'entry_logs_guest_idx' => 'entry_logs',
            'entry_logs_card_scanned_idx' => 'entry_logs',
            'entry_logs_scanned_desc_idx' => 'entry_logs',
            'entry_logs_result_scanned_idx' => 'entry_logs',
            'cards_guest_id_desc_idx' => 'cards',
            'cards_expires_at_idx' => 'cards',
            'cards_guest_status_idx' => 'cards',
            'cards_status_type_idx' => 'cards',
            'guests_email_idx' => 'guests',
            'guests_full_name_id_idx' => 'guests',
            'guests_phone_e164_idx' => 'guests',
        ] as $index => $table) {
            $this->dropIndexIfExists($table, $index);
        }
    }

    /**
     * Create an index if the table and its columns exist.
     *
     * Skipping missing columns keeps the migration forward-compatible: a
     * future migration that renames or drops a column will not make this one
     * explode halfway through a deploy.
     */
    private function addIndex(string $table, string $index, array $columns): void
    {
        if (! $this->tableAndColumnsExist($table, $columns)) {
            return;
        }

        if ($this->indexExists($table, $index)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $index) {
            $blueprint->index($columns, $index);
        });
    }

    /**
     * Drop an index, tolerating one the database will not let go.
     *
     * A rollback that dies halfway leaves the schema in a state nobody
     * planned for, which is worse than one that leaves an index behind. An
     * index backing a foreign key is the usual case.
     */
    private function dropIndexIfExists(string $table, string $index): void
    {
        if (! Schema::hasTable($table) || ! $this->indexExists($table, $index)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($index) {
                $blueprint->dropIndex($index);
            });
        } catch (\Throwable $e) {
            // Most often error 1553: "Cannot drop index ... needed in a
            // foreign key constraint". Leaving the index in place is safe;
            // dying halfway through a rollback is not.
        }
    }

    private function tableAndColumnsExist(string $table, array $columns): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        $present = array_map(
            fn (string $column): string => strtolower($column),
            Schema::getColumnListing($table)
        );

        foreach ($columns as $column) {
            if (! in_array(strtolower($column), $present, true)) {
                return false;
            }
        }

        return true;
    }

    private function indexExists(string $table, string $index): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            foreach (Schema::getIndexes($table) as $existing) {
                if (($existing['name'] ?? null) === $index) {
                    return true;
                }
            }

            return false;
        }

        foreach (Schema::getIndexes($table) as $existing) {
            if (($existing['name'] ?? null) === $index) {
                return true;
            }
        }

        return false;
    }
};

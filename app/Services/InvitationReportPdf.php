<?php

namespace App\Services;

/** Small dependency-free PDF writer for the invitation report. */
class InvitationReportPdf
{
    private const WIDTH = 842;
    private const HEIGHT = 595;
    private const MARGIN = 34;

    /** @param array<string, mixed> $data */
    public function generate(array $data, string $kind = 'all'): string
    {
        $rows = collect($data['rows'] ?? [])->filter(fn (array $row) => match ($kind) {
            'delivered' => $row['sms_status'] === 'delivered',
            'failed' => $row['sms_status'] === 'failed',
            'pending' => in_array($row['sms_status'], ['pending', 'sent'], true),
            default => true,
        })->values()->all();
        $summary = $this->summary($rows);
        $pages = [];
        $page = $this->newPage($data, $summary, $kind, count($rows));
        $y = $this->tableHeader($page, 335);
        $number = 1;

        foreach ($rows as $row) {
            $nameLines = $this->wrap($row['guest_name'] ?? '-', 30, 2);
            $phoneLines = $this->wrap($row['phone'] ?? '-', 20, 2);
            $cardLines = $this->wrap($row['card_number'] ?? '-', 18, 2);
            $height = max(22, max(count($nameLines), count($phoneLines), count($cardLines)) * 10 + 8);
            if ($y - $height < 48) {
                $pages[] = $page;
                $page = $this->newPage($data, $summary, $kind, count($rows), true);
                $y = $this->tableHeader($page, 535);
            }

            $page .= sprintf("1 1 1 rg %d %d %d %d re f\n", self::MARGIN, $y - $height, self::WIDTH - 2 * self::MARGIN, $height);
            $page .= sprintf("0.86 0.89 0.91 RG 0.45 w %d %d m %d %d l S\n", self::MARGIN, $y - $height, self::WIDTH - self::MARGIN, $y - $height);
            $cells = [
                (string) $number++, implode("\n", $nameLines), implode("\n", $phoneLines), implode("\n", $cardLines),
                ucfirst((string) ($row['card_type'] ?? '-')), strtoupper((string) ($row['sms_status'] ?? 'pending')),
                strtoupper($this->overall((string) ($row['sms_status'] ?? 'pending'))),
            ];
            $x = self::MARGIN + 5;
            $widths = [30, 168, 112, 115, 68, 112, 135];
            foreach ($cells as $index => $cell) {
                if ($index === 5 || $index === 6) {
                    $color = $this->statusColor((string) ($row['sms_status'] ?? 'pending'));
                    $page .= sprintf("%.3f %.3f %.3f rg\n", ...$color);
                } else {
                    $page .= "0.12 0.18 0.25 rg\n";
                }
                $page .= $this->text($x, $y - 14, $cell, 8, $index === 6);
                $x += $widths[$index];
            }
            $y -= $height;
        }
        $pages[] = $page;

        return $this->assemble($pages);
    }

    /** @param array<string, mixed> $data @param array<string, int> $summary */
    private function newPage(array $data, array $summary, string $kind, int $total, bool $continuation = false): string
    {
        $title = match ($kind) {
            'delivered' => 'SUCCESSFULLY DELIVERED GUESTS',
            'failed' => 'FAILED SMS REPORT',
            'pending' => 'PENDING SMS REPORT',
            default => 'INVITATION & SMS DELIVERY REPORT',
        };
        $out = "q\n";
        if (! $continuation) {
            $out .= "0.020 0.588 0.412 rg 0 493 842 102 re f\n";
            $out .= $this->text(36, 559, 'DIGITAL ELECTRONIC CARDS MANAGEMENT SYSTEM', 17, true, [1, 1, 1]);
            $out .= $this->text(36, 531, $title, 12, true, [1, 1, 1]);
            $event = $data['rows'][0]['event_name'] ?? 'All events';
            $date = $data['rows'][0]['event_date'] ?? '-';
            $venue = $data['rows'][0]['venue'] ?? '-';
            $meta = [
                'EVENT: ' . ($data['filters']['event'] ?? $event),
                'DATE: ' . $date,
                'VENUE: ' . $venue,
                'GENERATED: ' . ($data['generated_at'] ?? ''),
                'REFERENCE: ' . ($data['campaign_reference'] ?? ''),
            ];
            $x = 36;
            foreach ($meta as $item) {
                $out .= $this->text($x, 471, $item, 7, false, [0.22, 0.28, 0.35]);
                $x += 154;
            }
            $labels = [
                'REGISTERED GUESTS' => $summary['guests'], 'CARDS ASSIGNED' => $summary['cards'],
                'SMS SENT' => $summary['sent'], 'DELIVERED' => $summary['delivered'],
                'FAILED' => $summary['failed'], 'PENDING' => $summary['pending'],
            ];
            $cardWidth = 122;
            $x = self::MARGIN;
            foreach ($labels as $label => $value) {
                $out .= sprintf("1 1 1 rg 0.82 0.87 0.89 RG 0.7 w %d 389 %d 52 re B\n", $x, $cardWidth);
                $out .= $this->text($x + 7, 420, $label, 6.5, false, [0.31, 0.38, 0.4]);
                $out .= $this->text($x + 7, 398, (string) $value, 14, true, [0.02, 0.48, 0.34]);
                $x += $cardWidth + 8;
            }
        } else {
            $out .= "0.020 0.588 0.412 rg 0 552 842 43 re f\n";
            $out .= $this->text(36, 568, 'DIGITAL ELECTRONIC CARDS MANAGEMENT SYSTEM - ' . $title, 11, true, [1, 1, 1]);
        }
        $out .= $this->text(36, 25, 'DIGITAL ELECTRONIC CARDS MANAGEMENT SYSTEM', 7, false, [0.4, 0.45, 0.5]);
        $out .= $this->text(735, 25, 'PAGE {PAGE}', 7, false, [0.4, 0.45, 0.5]);
        return $out;
    }

    private function tableHeader(string &$page, int $top): int
    {
        $x = self::MARGIN;
        $widths = [30, 168, 112, 115, 68, 112, 135];
        $labels = ['No.', 'Guest Name', 'Phone Number', 'Card Number', 'Card Type', 'SMS Status', 'Overall Status'];
        $page .= sprintf("0.88 0.97 0.93 rg %d %d %d 22 re f\n", self::MARGIN, $top - 22, self::WIDTH - 2 * self::MARGIN);
        foreach ($labels as $index => $label) {
            $page .= $this->text($x + 5, $top - 15, $label, 7.5, true, [0.02, 0.39, 0.29]);
            $x += $widths[$index];
        }
        return $top - 22;
    }

    /** @param array<int, array<string, mixed>> $rows @return array<string, int> */
    private function summary(array $rows): array
    {
        $counts = array_count_values(array_column($rows, 'sms_status'));
        return [
            'guests' => count(array_unique(array_column($rows, 'guest_id'))),
            'cards' => count(array_filter($rows, fn ($row) => ! in_array($row['card_number'] ?? '-', ['-', '—'], true))),
            'sent' => ($counts['sent'] ?? 0) + ($counts['delivered'] ?? 0),
            'delivered' => $counts['delivered'] ?? 0,
            'failed' => $counts['failed'] ?? 0,
            'pending' => ($counts['pending'] ?? 0) + ($counts['sent'] ?? 0),
        ];
    }

    private function overall(string $status): string
    {
        return match ($status) {
            'delivered' => 'Successful', 'failed' => 'Failed', default => 'Pending',
        };
    }

    /** @return array{float, float, float} */
    private function statusColor(string $status): array
    {
        return match ($status) {
            'delivered' => [0.04, 0.42, 0.28], 'failed' => [0.72, 0.13, 0.16], default => [0.62, 0.39, 0.04],
        };
    }

    /** @return list<string> */
    private function wrap(string $text, int $limit, int $maxLines): array
    {
        $text = $this->ascii($text);
        $words = preg_split('/\s+/', trim($text)) ?: ['-'];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            if ($line !== '' && strlen($line . ' ' . $word) > $limit) {
                $lines[] = $line;
                $line = '';
                if (count($lines) >= $maxLines) break;
            }
            $line = $line === '' ? $word : $line . ' ' . $word;
        }
        if ($line !== '' && count($lines) < $maxLines) $lines[] = $line;
        if (count($lines) === $maxLines && strlen(implode(' ', $words)) > strlen(implode(' ', $lines))) {
            $lines[$maxLines - 1] = rtrim(substr($lines[$maxLines - 1], 0, max(1, $limit - 3))) . '...';
        }
        return $lines ?: ['-'];
    }

    /** PDF built-in fonts use WinAnsi; transliteration keeps report text readable without external fonts. */
    private function ascii(string $text): string
    {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        return $converted === false ? $text : $converted;
    }

    /** @param array{float, float, float}|null $color */
    private function text(float $x, float $y, string $text, float $size, bool $bold = false, ?array $color = null): string
    {
        $color ??= [0.12, 0.18, 0.25];
        $out = sprintf("BT %.3f %.3f %.3f rg /%s %.2f Tf %.2f %.2f Td ", ...[...$color, $bold ? 'F2' : 'F1', $size, $x, $y]);
        foreach (explode("\n", $text) as $index => $line) {
            if ($index > 0) $out .= sprintf("0 -10 Td ");
            $line = str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $this->ascii($line));
            $out .= '(' . $line . ') Tj ';
        }
        return $out . "ET\n";
    }

    /** @param list<string> $pages */
    private function assemble(array $pages): string
    {
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', 4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>'];
        $kids = [];
        foreach ($pages as $index => $content) {
            $pageId = 5 + $index * 2;
            $streamId = $pageId + 1;
            $kids[] = $pageId . ' 0 R';
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $streamId . ' 0 R >>';
            $content = str_replace('{PAGE}', (string) ($index + 1), $content);
            $objects[$streamId] = '<< /Length ' . strlen($content) . ">>\nstream\n" . $content . "endstream";
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($pages) . ' >>';
        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $size = max(array_keys($objects)) + 1;
        $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";
        for ($id = 1; $id < $size; $id++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        return $pdf . "trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }
}

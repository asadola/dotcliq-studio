<?php
declare(strict_types=1);

/** Active staff for assignment dropdowns. */
function staff_list(array $roles = []): array
{
    if ($roles) {
        $in = implode(',', array_fill(0, count($roles), '?'));
        return rows("SELECT id, name, role FROM users WHERE active = 1 AND role IN ($in) ORDER BY name", $roles);
    }
    return rows('SELECT id, name, role FROM users WHERE active = 1 ORDER BY name');
}

/**
 * Create a client, or return the id of an existing client with the same phone.
 * @return array{0:int,1:bool} [clientId, wasExisting]
 */
function find_or_create_client(string $name, string $phone, string $email = '', string $notes = ''): array
{
    $phone = mb_substr(trim($phone), 0, 40);
    if ($phone !== '') {
        $digits = preg_replace('/\D+/', '', $phone);
        foreach (rows("SELECT id, phone FROM clients WHERE phone IS NOT NULL AND phone <> ''") as $c) {
            if ($digits !== '' && preg_replace('/\D+/', '', (string) $c['phone']) === $digits) {
                return [(int) $c['id'], true];
            }
        }
    }
    q('INSERT INTO clients (name, phone, email, notes, created_by, created_at) VALUES (?,?,?,?,?,?)', [
        mb_substr($name, 0, 140), $phone ?: null, mb_substr($email, 0, 160) ?: null, $notes ?: null,
        user()['id'] ?? null, date('Y-m-d H:i:s'),
    ]);
    return [last_id(), false];
}

/** Kenyan-friendly WhatsApp number (digits only, country code 254). */
function wa_number(string $phone): string
{
    $d = preg_replace('/\D+/', '', $phone);
    if ($d === '') {
        return '';
    }
    if (str_starts_with($d, '0')) {
        return '254' . substr($d, 1);
    }
    if (strlen($d) === 9 && ($d[0] === '7' || $d[0] === '1')) {
        return '254' . $d;
    }
    return $d;
}

function sale_totals(int $saleId): ?array
{
    return row('SELECT s.*, COALESCE(p.paid, 0) AS paid ' . SALES_FROM . ' WHERE s.id = ?', [$saleId]);
}

const JOB_TYPES = ['retouch' => 'Photo retouching', 'video_edit' => 'Video editing', 'album' => 'Album design', 'print' => 'Printing', 'other' => 'Other'];
const JOB_STATUSES = ['queued' => 'Queued', 'in_progress' => 'In progress', 'review' => 'In review', 'delivered' => 'Delivered'];
const BOOKING_STATUSES = ['pending' => 'Pending', 'confirmed' => 'Confirmed', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];

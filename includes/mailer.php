<?php
// Email notifications. Every email is written to the email_log table so it can be
// viewed in Admin > Email Log. If MAIL_ENABLED is true it is also sent with mail().

function send_email(?string $to, string $subject, string $htmlBody, ?int $bookingId = null): void
{
    if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) return;

    $status = 'logged';
    if (MAIL_ENABLED) {
        $headers = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: " . MAIL_FROM . "\r\n";
        $status = @mail($to, $subject, email_layout($subject, $htmlBody), $headers) ? 'sent' : 'failed';
    }
    db_exec(
        'INSERT INTO email_log (booking_id, to_email, subject, body, status) VALUES (?, ?, ?, ?, ?)',
        [$bookingId, $to, $subject, $htmlBody, $status]
    );
}

function email_layout(string $title, string $body): string
{
    return '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;border:1px solid #ddd;border-radius:8px;overflow:hidden">' .
        '<div style="background:#3D583C;color:#fff;padding:16px 20px;font-size:18px">' . e(setting('resort_name', "J'Maire Farm")) . '</div>' .
        '<div style="padding:20px;color:#333;line-height:1.5">' . $body . '</div></div>';
}

/** Standard notification emails for each booking event. */
function notify_booking(int $bookingId, string $event, string $extra = ''): void
{
    $b = get_booking($bookingId);
    if (!$b || !$b['guest_email']) return;

    $link = absolute_url('booking.php?code=' . urlencode($b['booking_code']));
    $summary = '<p><strong>Booking code:</strong> ' . e($b['booking_code']) . '<br>' .
        '<strong>Accommodation:</strong> ' . e($b['accommodation_name']) . '<br>' .
        '<strong>Date:</strong> ' . e(stay_label($b)) . '<br>' .
        '<strong>Total:</strong> ' . money($b['total_amount']) . '</p>';

    switch ($event) {
        case 'created':
            $subject = 'Booking received - ' . $b['booking_code'];
            $body = '<p>Hi ' . e($b['guest_name']) . ',</p><p>We received your booking. Your date is on hold until <strong>' .
                e(fmt_datetime($b['expires_at'])) . '</strong>. Please pay the downpayment of <strong>' . money($b['downpayment_due']) .
                '</strong> and upload your receipt.</p>' . $summary;
            break;
        case 'confirmed':
            $subject = 'Booking confirmed - ' . $b['booking_code'];
            $body = '<p>Hi ' . e($b['guest_name']) . ',</p><p>Your payment was verified and your booking is <strong>confirmed</strong>. See you at the farm!</p>' . $summary;
            break;
        case 'payment_rejected':
            $subject = 'Please re-upload your receipt - ' . $b['booking_code'];
            $body = '<p>Hi ' . e($b['guest_name']) . ',</p><p>We could not verify your payment receipt.</p><p><strong>Reason:</strong> ' .
                e($extra) . '</p><p>Please upload a new receipt before ' . e(fmt_datetime($b['expires_at'])) . ' to keep your date.</p>' . $summary;
            break;
        case 'cancelled':
            $subject = 'Booking cancelled - ' . $b['booking_code'];
            $body = '<p>Hi ' . e($b['guest_name']) . ',</p><p>Your booking was cancelled.' . ($extra ? ' Reason: ' . e($extra) : '') . '</p>' . $summary;
            break;
        case 'rejected':
            $subject = 'Booking not approved - ' . $b['booking_code'];
            $body = '<p>Hi ' . e($b['guest_name']) . ',</p><p>Sorry, we could not approve your booking.' . ($extra ? ' Reason: ' . e($extra) : '') . '</p>' . $summary;
            break;
        case 'expired':
            $subject = 'Booking expired - ' . $b['booking_code'];
            $body = '<p>Hi ' . e($b['guest_name']) . ',</p><p>We did not receive a payment in time, so your booking expired and the date was released. You are welcome to book again.</p>' . $summary;
            break;
        case 'completed':
            $subject = 'Thank you for staying with us - ' . $b['booking_code'];
            $body = '<p>Hi ' . e($b['guest_name']) . ',</p><p>Thank you for visiting! Your official receipt is available here: <a href="' .
                e(absolute_url('receipt.php?code=' . urlencode($b['booking_code']))) . '">view receipt</a>.</p>' . $summary;
            break;
        default:
            return;
    }
    $body .= '<p><a href="' . e($link) . '" style="background:#3D583C;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none">View booking</a></p>';
    send_email($b['guest_email'], $subject, $body, $bookingId);
}

function absolute_url(string $path): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . url($path);
}

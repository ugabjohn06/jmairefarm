<?php
// Audit log: who did what, and when.

function audit(string $action, ?string $targetType = null, ?int $targetId = null, string $details = ''): void
{
    $user = current_user();
    db_exec(
        'INSERT INTO audit_logs (user_id, action, target_type, target_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)',
        [$user['id'] ?? null, $action, $targetType, $targetId, mb_substr($details, 0, 500), client_ip()]
    );
}

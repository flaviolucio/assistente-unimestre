<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Core\Database;
use App\Core\Logger;

final class Audit
{
    public static function record(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        array $details = []
    ): void {
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            self::currentUserId(),
            $action,
            $entityType,
            $entityId,
            $details !== [] ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            client_ip(),
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'cli'), 0, 255),
        ]);

        Logger::debug('app', 'Auditoria registrada', ['action' => $action]);
    }

    private static function currentUserId(): ?int
    {
        $sessionId = $_SESSION['_auth_user_id'] ?? null;

        return is_numeric($sessionId) ? (int) $sessionId : null;
    }
}
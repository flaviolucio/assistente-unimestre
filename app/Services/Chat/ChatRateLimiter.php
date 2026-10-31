<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Core\Database;
use App\Repositories\SystemSettingRepository;

/**
 * Rate limit por usuário autenticado para o chat (sem Redis, em banco),
 * em janela fixa de 60 segundos.
 *
 * Melhor esforço e documentado como tal: o incremento é atômico por linha
 * (SELECT ... FOR UPDATE) dentro de uma transação curta.
 */
final class ChatRateLimiter
{
    public const WINDOW_SECONDS = 60;

    public function limit(): int
    {
        $fromSetting = (new SystemSettingRepository())->get('chat.rate_limit_per_minute');

        if ($fromSetting !== null && is_numeric($fromSetting)) {
            $limit = (int) $fromSetting;
        } else {
            $limit = (int) config('chat.rate_limit_per_minute', 10);
        }

        return max(1, $limit);
    }

    /**
     * Registra (ou atualiza) a janela e retorna true se a requisição é
     * permitida. False indica que o limite foi atingido (HTTP 429).
     */
    public function allow(int $userId): bool
    {
        $limit = $this->limit();
        $now = date('Y-m-d H:i:s');
        $db = Database::connection();

        $db->beginTransaction();

        try {
            $stmt = $db->prepare('SELECT id, window_started_at, request_count FROM chat_limits WHERE user_id = ? FOR UPDATE');
            $stmt->execute([$userId]);
            $row = $stmt->fetch();

            $cutoff = date('Y-m-d H:i:s', time() - self::WINDOW_SECONDS);

            if ($row === false) {
                $db->prepare(
                    'INSERT INTO chat_limits (user_id, window_started_at, request_count) VALUES (?, ?, 1)'
                )->execute([$userId, $now]);
                $db->commit();

                return true;
            }

            if ((string) $row['window_started_at'] < $cutoff || (int) $row['request_count'] < 1) {
                $db->prepare(
                    'UPDATE chat_limits SET window_started_at = ?, request_count = 1 WHERE id = ?'
                )->execute([$now, (int) $row['id']]);
                $db->commit();

                return true;
            }

            if ((int) $row['request_count'] >= $limit) {
                $db->rollBack();

                return false;
            }

            $db->prepare(
                'UPDATE chat_limits SET request_count = request_count + 1, window_started_at = ? WHERE id = ?'
            )->execute([$now, (int) $row['id']]);
            $db->commit();

            return true;
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            // Em caso de falha de infraestrutura do controle, permite-se a
            // requisição para não derrubar o atendimento (fail-open
            // documentado; a persistência da conversa segue transacional).
            return true;
        }
    }

    public function reset(int $userId): void
    {
        Database::connection()->prepare('DELETE FROM chat_limits WHERE user_id = ?')->execute([$userId]);
    }
}
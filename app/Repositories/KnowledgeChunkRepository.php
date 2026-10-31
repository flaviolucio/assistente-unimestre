<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class KnowledgeChunkRepository
{
    /**
     * Substitui todos os chunks de um documento (usado na reindexação).
     * Deve ser chamado dentro de uma transação que também cubra a escrita
     * do documento, para nunca deixar o documento parcialmente indexado.
     *
     * @param array<int, array{content: string, token_estimate: int, search_text: string}> $chunks
     */
    public function replaceForDocument(int $documentId, array $chunks): int
    {
        $db = Database::connection();

        $this->deleteForDocument($documentId);

        if ($chunks === []) {
            return 0;
        }

        $stmt = $db->prepare(
            'INSERT INTO knowledge_chunks (document_id, chunk_index, content, token_estimate, search_text)
             VALUES (?, ?, ?, ?, ?)'
        );

        $index = 0;

        foreach ($chunks as $chunk) {
            $stmt->execute([
                $documentId,
                $index,
                $chunk['content'],
                (int) $chunk['token_estimate'],
                $chunk['search_text'],
            ]);
            $index++;
        }

        return count($chunks);
    }

    public function deleteForDocument(int $documentId): int
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM knowledge_chunks WHERE document_id = ?'
        );
        $stmt->execute([$documentId]);

        return $stmt->rowCount();
    }

    public function countForDocument(int $documentId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM knowledge_chunks WHERE document_id = ?'
        );
        $stmt->execute([$documentId]);

        return (int) $stmt->fetchColumn();
    }

    public function countTotal(): int
    {
        return (int) Database::connection()->query(
            'SELECT COUNT(*) FROM knowledge_chunks'
        )->fetchColumn();
    }

    public function findByDocument(int $documentId, bool $includeSearchText = false): array
    {
        $columns = $includeSearchText
            ? 'id, document_id, chunk_index, content, token_estimate, search_text, created_at'
            : 'id, document_id, chunk_index, content, token_estimate, created_at';

        $stmt = Database::connection()->prepare(
            'SELECT ' . $columns . ' FROM knowledge_chunks WHERE document_id = ? ORDER BY chunk_index ASC'
        );
        $stmt->execute([$documentId]);

        return $stmt->fetchAll();
    }
}
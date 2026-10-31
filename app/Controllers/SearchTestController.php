<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\RAG\RetrievalService;
use App\Services\RAG\SearchResult;

final class SearchTestController extends Controller
{
    public function index(): void
    {
        $this->render('search-test/index', [
            'appTitle' => 'Testar recuperação',
            'query' => '',
            'result' => null,
            'errors' => [],
        ]);
    }

    public function search(): void
    {
        $query = trim((string) ($_POST['query'] ?? ''));

        if ($query === '') {
            $this->render('search-test/index', [
                'appTitle' => 'Testar recuperação',
                'query' => '',
                'result' => null,
                'errors' => ['query' => 'Informe a pergunta para pesquisar.'],
            ]);

            return;
        }

        $service = new RetrievalService();
        $result = $service->search($query);
        $result['results'] = array_map(
            static fn (SearchResult $item): array => $item->toArray(),
            $result['results']
        );

        $this->render('search-test/index', [
            'appTitle' => 'Testar recuperação',
            'query' => $query,
            'result' => $result,
            'errors' => [],
        ]);
    }
}
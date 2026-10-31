<?php

declare(strict_types=1);

return [
    'categories' => [
        'Acadêmico',
        'Financeiro',
        'Portal',
        'Relatórios',
        'Configurações',
        'Biblioteca',
        'EAD',
        'Integrações',
        'Outros',
    ],
    'origins' => [
        'official' => 'Documentação oficial UNIMESTRE',
        'internal' => 'Base interna',
    ],
    'upload' => [
        'max_size' => 2 * 1024 * 1024,
        'allowed_extensions' => ['txt', 'md'],
        'allowed_mimes' => [
            'text/plain',
            'text/markdown',
            'text/x-markdown',
            'application/octet-stream',
        ],
    ],
    'pagination' => [
        'per_page' => 20,
    ],

    'chunking' => [
        'target_tokens' => 450,
        'max_tokens' => 650,
        'overlap_tokens' => 80,
        'min_chunk_tokens' => 60,
        'chars_per_token' => 4,
    ],

    // Importação em massa da documentação oficial a partir de ARQUIVOS LOCAIS
    // (páginas salvas pelo navegador ou exportação oficial). Nenhuma
    // requisição é feita ao site do UNIMESTRE.
    'bulk_import' => [
        'allowed_hosts' => ['unimestre.com', 'www.unimestre.com', 'manual.unimestre.com'],
        'dir' => APP_PATH . '/storage/imports/unimestre',
        'state_dir' => APP_PATH . '/storage/imports/state',
        'extensions' => ['html', 'htm', 'txt', 'md', 'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'],
        // ZIP: limites contra zip bomb.
        'max_zip_entries' => 10000,
        'max_uncompressed_bytes' => 500 * 1024 * 1024,
        'max_file_bytes' => 5 * 1024 * 1024,
        'max_files' => 3000,
        'max_zip_bytes' => 100 * 1024 * 1024,
        'batch_size' => 25,
        'min_content_chars' => 80,
        // Mapeia a taxonomia ORIGINAL da fonte para as categorias do sistema.
        // A categoria original é preservada em subcategory/metadata.
        'category_map' => [
            'Portal' => ['portal', 'acesso', 'senha', 'login', 'aluno online', 'professor online'],
            'Acadêmico' => ['academ', 'nota', 'avalia', 'diario', 'frequencia', 'falta', 'matricula', 'turma', 'disciplina', 'curso', 'boletim', 'historico', 'secretaria', 'grade', 'horario', 'media'],
            'Financeiro' => ['financ', 'boleto', 'cobranca', 'mensalidade', 'pagamento', 'contas a receber', 'titulo', 'bolsa', 'desconto', 'nota fiscal'],
            'Relatórios' => ['relatorio'],
            'Configurações' => ['configura', 'parametro', 'administra', 'permiss', 'usuario', 'seguranca', 'cadastro'],
            'Biblioteca' => ['bibliotec', 'acervo', 'emprestimo'],
            'EAD' => ['ead', 'ava', 'moodle', 'ensino a distancia'],
            'Integrações' => ['integra', 'api', 'webservice', 'importa', 'exporta', 'sincroniza'],
        ],
    ],

    'retrieval' => [
        // Padrões. Override administrativo: system_settings rag.top_k / rag.min_score.
        'top_k' => 5,
        'minimum_relevance_score' => 2.0,
        // Relevância base: fração mínima dos termos da pergunta que o candidato
        // precisa conter (título/conteúdo/tags) ANTES de qualquer bônus.
        'min_term_coverage' => 0.3,
        'official_source_boost' => 0.5,
        'candidate_limit' => 30,
        'weights' => [
            'fulltext' => 5.0,
            'title' => 2.0,
            'category' => 1.0,
            'subcategory' => 0.5,
            'tags' => 1.0,
            'content' => 1.0,
            'exact' => 0.5,
        ],
    ],

    'search' => [
        'min_term_length' => 2,
        'stopwords' => [
            'como',
            'para',
            'uma',
            'um',
            'de',
            'do',
            'da',
            'que',
            'com',
            'em',
            'no',
            'na',
            'os',
            'as',
            'nao',
            'não',
            'por',
            'pela',
            'pelo',
            'se',
            'tem',
            'ter',
            'esta',
            'meu',
            'sua',
            'fazer',
            'faco',
            'faço',
            // Palavras de função genéricas do português (artigos, pronomes,
            // verbos auxiliares, conectivos, quantificadores). Não carregam
            // assunto e geravam relevância falsa por coincidência textual.
            'ao', 'aos', 'dos', 'das', 'nos', 'nas', 'num', 'numa', 'mas', 'ou', 'já', 'ja',
            'mais', 'menos', 'muito', 'muita', 'muitos', 'muitas', 'pouco', 'todo', 'toda',
            'todos', 'todas', 'tudo', 'este', 'estes', 'estas', 'esse', 'essa', 'esses',
            'essas', 'isto', 'isso', 'aquele', 'aquela', 'ele', 'ela', 'eles', 'elas', 'eu',
            'você', 'voce', 'nós', 'lhe', 'seu', 'seus', 'suas', 'minha', 'minhas', 'meus',
            'nosso', 'nossa', 'está', 'estão', 'estao', 'estava', 'estou', 'ser', 'sendo',
            'sido', 'foi', 'foram', 'são', 'sao', 'era', 'há', 'ha', 'tenho', 'tinha', 'pode',
            'podem', 'deve', 'devem', 'quando', 'onde', 'qual', 'quais', 'quem', 'porque',
            'pois', 'então', 'entao', 'assim', 'também', 'tambem', 'ainda', 'sobre', 'entre',
            'até', 'ate', 'após', 'apos', 'sem', 'cada', 'outro', 'outra', 'outros', 'outras',
            'mesmo', 'mesma', 'bem', 'só', 'so',
        ],
    ],
];
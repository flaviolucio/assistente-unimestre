<?php

declare(strict_types=1);

$router->get('/', 'HomeController@index');
$router->get('/diagnostico', 'DiagnosticController@index', ['auth', 'permission:diagnostic.view']);
$router->get('/api/diagnostico', 'DiagnosticController@api', ['auth', 'permission:diagnostic.view']);

$router->get('/login', 'AuthController@showLogin');
$router->post('/login', 'AuthController@login');
$router->post('/logout', 'AuthController@logout', ['auth']);

$router->get('/dashboard', 'DashboardController@index', ['auth']);
$router->get('/alterar-senha', 'PasswordController@showChange', ['auth']);
$router->post('/alterar-senha', 'PasswordController@change', ['auth']);

$router->get('/usuarios', 'UserController@index', ['auth', 'permission:users.manage']);
$router->get('/usuarios/novo', 'UserController@createForm', ['auth', 'permission:users.manage']);
$router->post('/usuarios', 'UserController@store', ['auth', 'permission:users.manage']);
$router->get('/usuarios/{id}/editar', 'UserController@editForm', ['auth', 'permission:users.manage']);
$router->post('/usuarios/{id}/editar', 'UserController@update', ['auth', 'permission:users.manage']);
$router->post('/usuarios/{id}/resetar-senha', 'UserController@resetPassword', ['auth', 'permission:users.manage']);
$router->post('/usuarios/{id}/status', 'UserController@toggleStatus', ['auth', 'permission:users.manage']);
$router->post('/usuarios/{id}/excluir', 'UserController@destroy', ['auth', 'permission:users.manage']);

$router->get('/knowledge', 'KnowledgeController@index', ['auth', 'permission:knowledge.view']);
$router->get('/knowledge/{id}', 'KnowledgeController@show', ['auth', 'permission:knowledge.view']);
$router->get('/admin/knowledge/import', 'KnowledgeController@importForm', ['auth', 'permission:documents.manage']);
$router->post('/admin/knowledge/import', 'KnowledgeController@import', ['auth', 'permission:documents.manage']);
$router->get('/admin/knowledge/{id}/edit', 'KnowledgeController@editForm', ['auth', 'permission:documents.manage']);
$router->post('/admin/knowledge/{id}/edit', 'KnowledgeController@update', ['auth', 'permission:documents.manage']);
$router->post('/admin/knowledge/{id}/status', 'KnowledgeController@toggleStatus', ['auth', 'permission:documents.manage']);

// Importação em massa da documentação oficial (arquivos locais; somente admin)
$router->get('/admin/knowledge/importacao-oficial', 'KnowledgeImportController@index', ['auth', 'permission:knowledge.bulk_import']);
$router->post('/admin/knowledge/importacao-oficial/upload', 'KnowledgeImportController@upload', ['auth', 'permission:knowledge.bulk_import']);
$router->get('/admin/knowledge/importacao-oficial/{batch}/previa/{item}', 'KnowledgeImportController@preview', ['auth', 'permission:knowledge.bulk_import']);
$router->post('/admin/knowledge/importacao-oficial/analisar', 'KnowledgeImportController@analyze', ['auth', 'permission:knowledge.bulk_import']);
$router->post('/admin/knowledge/importacao-oficial/{batch}/fila', 'KnowledgeImportController@queue', ['auth', 'permission:knowledge.bulk_import']);
$router->post('/admin/knowledge/importacao-oficial/{batch}/lote', 'KnowledgeImportController@batch', ['auth', 'permission:knowledge.bulk_import']);

$router->get('/solutions', 'SolutionController@index', ['auth', 'permission:solutions.view']);
$router->get('/solutions/novo', 'SolutionController@createForm', ['auth', 'permission:solutions.manage']);
$router->post('/solutions', 'SolutionController@store', ['auth', 'permission:solutions.manage']);
$router->get('/solutions/{id}/editar', 'SolutionController@editForm', ['auth', 'permission:solutions.manage']);
$router->post('/solutions/{id}/editar', 'SolutionController@update', ['auth', 'permission:solutions.manage']);
$router->post('/solutions/{id}/status', 'SolutionController@toggleStatus', ['auth', 'permission:solutions.manage']);

$router->get('/admin/search-test', 'SearchTestController@index', ['auth', 'permission:search.test']);
$router->post('/admin/search-test', 'SearchTestController@search', ['auth', 'permission:search.test']);

$router->get('/admin/ai', 'AiController@index', ['auth', 'permission:ai.view']);
$router->post('/admin/ai/{id}/salvar', 'AiController@save', ['auth', 'permission:ai.manage']);
$router->post('/admin/ai/{id}/status', 'AiController@toggleStatus', ['auth', 'permission:ai.manage']);
$router->post('/admin/ai/{id}/test', 'AiController@test', ['auth', 'permission:ai.test']);
$router->post('/admin/ai/{id}/remover-chave', 'AiController@removeKey', ['auth', 'permission:ai.manage']);
$router->post('/admin/ai/fallback', 'AiController@saveFallback', ['auth', 'permission:ai.manage']);
$router->post('/admin/ai/{id}/prioridade', 'AiController@movePriority', ['auth', 'permission:ai.manage']);
$router->get('/admin/ai/playground', 'AiController@playground', ['auth', 'permission:ai.test']);
$router->post('/admin/ai/playground', 'AiController@playgroundSend', ['auth', 'permission:ai.test']);

$router->get('/chat', 'ChatController@index', ['auth']);
$router->get('/chat/{id}', 'ChatController@show', ['auth']);
$router->get('/chat/{id}/salvar-solucao', 'ChatController@saveSolutionForm', ['auth', 'permission:solutions.manage']);
$router->get('/chat/{id}/caso-resolvido', 'InternalKnowledgeController@caseForm', ['auth', 'permission:solutions.manage']);
$router->post('/chat/{id}/caso-resolvido', 'InternalKnowledgeController@caseStore', ['auth', 'permission:solutions.manage']);
// Endereço usado pelas telas: a hospedagem atual retorna 403 antes da aplicação em /chat.
$router->get('/assistente', 'ChatController@index', ['auth']);
$router->get('/assistente/{id}', 'ChatController@show', ['auth']);
$router->get('/assistente/{id}/salvar-solucao', 'ChatController@saveSolutionForm', ['auth', 'permission:solutions.manage']);
$router->get('/assistente/{id}/caso-resolvido', 'InternalKnowledgeController@caseForm', ['auth', 'permission:solutions.manage']);
$router->post('/assistente/{id}/caso-resolvido', 'InternalKnowledgeController@caseStore', ['auth', 'permission:solutions.manage']);
$router->get('/procedimentos/novo', 'InternalKnowledgeController@procedureForm', ['auth', 'permission:solutions.manage']);
$router->post('/procedimentos', 'InternalKnowledgeController@procedureStore', ['auth', 'permission:solutions.manage']);
$router->get('/conhecimento-interno/{id}/editar', 'InternalKnowledgeController@editForm', ['auth', 'permission:solutions.manage']);
$router->post('/conhecimento-interno/{id}/editar', 'InternalKnowledgeController@update', ['auth', 'permission:solutions.manage']);
$router->get('/history', 'ChatController@history', ['auth']);
$router->post('/api/chat', 'ChatController@api', ['auth']);
$router->post('/api/chat/{id}/feedback', 'ChatController@feedback', ['auth']);
$router->post('/api/chat/{id}/diagnostico/resolver', 'ChatController@diagnosticResolve', ['auth']);
$router->post('/api/chat/{id}/diagnostico/encerrar', 'ChatController@diagnosticAbandon', ['auth']);
$router->get('/api/chat/{id}/diagnostico/escalonamento', 'ChatController@diagnosticEscalation', ['auth']);
$router->post('/api/chat/{id}/diagnostico/escalar', 'ChatController@diagnosticEscalate', ['auth']);
$router->post('/api/chat/{id}/diagnostico/retomar', 'ChatController@diagnosticReopen', ['auth']);
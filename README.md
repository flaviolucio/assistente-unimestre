# Central Inteligente de Suporte UNIMESTRE

Assistente de IA (RAG) para suporte técnico do sistema UNIMESTRE.
A aplicação pesquisa a própria base de conhecimento e só então envia os trechos
relevantes para a IA, citando as fontes utilizadas.

> **Status atual: FASE 6 — RAG completo + chat com fontes.**
> As Fases 1 (fundação), 2 (autenticação, usuários, perfis e segurança), 3 (base
> de conhecimento e importação manual), 4 (chunking e busca MySQL com ranking) e
> 5 (camada de IA multi-provider, painel `/admin/ai`, playground e API Key
> criptografada) estão concluídas. Esta fase une a busca RAG ao provedor de IA:
> o chat (`/chat`, `/history`, `POST /api/chat`) injeta os trechos relevantes e
> as fontes no prompt, cita as fontes na resposta, persiste conversas/mensagens
> e segue regras de segurança (ownership, XSS escapado, rate limit, CSRF,
> idempotência e prompt injection). O chat final com streaming e a NVIDIA ficam
> para as próximas fases.

---

## 1. Requisitos

- Windows + XAMPP (Apache + MariaDB/MySQL + PHP 8.0+)
- PHP com extensões habilitadas: `pdo_mysql`, `openssl`, `mbstring`, `curl`, `fileinfo`
- MariaDB/MySQL 10.4+ (recomendado)
- Nenhuma dependência externa: **não** usa Composer, Node.js, Docker ou Redis

O ambiente foi validado com:
- PHP 8.0.30 (XAMPP)
- MariaDB 10.4.32-MariaDB

## 2. Estrutura do projeto

```
assistente-unimestre/
├── app/
│   ├── Controllers/          Controladores HTTP (inclui Knowledge e Solution)
│   ├── Core/                 Núcleo: Application, Router, Database, Config, Env, Logger, ErrorHandler
│   ├── Models/               (fases futuras)
│   ├── Services/             AI/ (Fase 5), Chat/ (Fase 6), Knowledge/ (Fase 3), RAG/ (Fase 4), Search/, Security/
│   ├── Repositories/         Knowledge (Fase 3), User, etc.
│   └── Helpers/              (fases futuras)
├── bootstrap/                Autoload PSR-4 próprio + boot da aplicação
├── config/app.php            Configuração central (env + padrões)
├── config/knowledge.php      Categorias, origens, limites de upload, chunking e busca (Fases 3+4)
├── config/permissions.php    Matriz de permissões por perfil
├── database/
│   ├── migrations/           Migrations SQL versionadas
│   ├── seeds/                Seeds de base
│   ├── migrate.php           Runner CLI de migrations
│   └── seed.php              Runner CLI de seeds
├── public/                   Front controller + assets (estático)
├── resources/views/          Views (layouts, home, diagnóstico, erros)
├── routes/web.php            Rotas da aplicação
├── storage/logs|cache        Logs e cache (não versionados)
├── tests/run.php             Suíte de testes
├── .env.example              Modelo de configuração (sem segredos)
└── index.php                 Fallback de entrada (Apache)
```

**Autoload:** foi implementado autoload PSR-4 próprio em `bootstrap/autoload.php`,
mapeando o namespace `App\` para `app/`. Composer foi **descartado** nesta fase
para evitar dependências desnecessárias (se for adotado no futuro, será
documentado aqui).

## 3. Instalação no XAMPP

1. Copie a pasta do projeto para `C:\xampp\htdocs\assistente-unimestre`
   (se ainda não estiver lá).
2. Confirme a porta do Apache local. Neste ambiente o Apache do XAMPP está
   configurado na porta **8182** (`C:\xampp\apache\conf\httpd.conf`, diretiva
   `Listen`). Ajuste `APP_URL` no `.env` para a porta que seu Apache utiliza.
3. O `mod_rewrite` precisa estar ativo (padrão do XAMPP) e o
   `AllowOverride All` para o `htdocs`.

## 4. Configuração do ambiente

1. Copie `.env.example` para `.env`:

   ```
   copy .env.example .env
   ```

2. Gere uma chave aleatória para `APP_KEY` (mínimo 32 caracteres hexadecimais):

   ```
   php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"
   ```

3. Ajuste as credenciais do banco em `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`,
   `DB_PASS` conforme sua instalação.
4. Gere a `APP_ENCRYPTION_KEY` (64 caracteres hexadecimais), usada para
   criptografar a API Key dos providers de IA:

   ```
   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
   ```

Nunca versione o arquivo `.env` (está no `.gitignore`).

## 5. Criação do banco e migrations

O banco (`assistente_unimestre` por padrão) é criado automaticamente pelo runner,
sem necessidade de phpMyAdmin.

Executar na raiz do projeto:

```
php database/migrate.php        # aplica migrations pendentes (equivale a "up")
php database/migrate.php status # lista aplicadas/pendentes
php database/migrate.php rollback  # reverte o último lote
```

Migrations criadas (15):

| Versão | Tabela |
|---|---|
| 0000 | `migrations` (controle interno) |
| 0001 | `users` |
| 0002 | `knowledge_sources` |
| 0003 | `knowledge_documents` |
| 0004 | `knowledge_chunks` |
| 0005 | `internal_solutions` |
| 0006 | `ai_providers` |
| 0007 | `conversations` |
| 0008 | `messages` |
| 0009 | `message_sources` |
| 0010 | `feedback` |
| 0011 | `system_settings` |
| 0012 | `audit_logs` |
| 0013 | `users` (+ `must_change_password`, `last_login_at`, `failed_login_attempts`, `locked_until`) |
| 0014 | `login_attempts` (rate limiting e auditoria de login) |
| 0015 | `messages` (+ `tokens_total`, `finish_reason`, `truncated`), `message_sources.solution_id`, UNIQUE `(message_id, user_id)` em `feedback`, `chat_limits` (Fase 6) |

Destaques de schema já preparados para a busca RAG:

- Índice `FULLTEXT` em `knowledge_chunks (content, search_text)` e em
  `knowledge_documents (title, content)`.
- Hash de conteúdo (`content_hash`, SHA-256) para detecção de duplicidade e
  sincronização futura.
- `metadata` em JSON para metadados de documentos/chunks.

## 6. Dados iniciais (seeds)

```
php database/seed.php       # executa seeds pendentes
php database/seed.php status
```

A seed de base cria:

- Usuário administrador (`admin@assistente.local`), senha padrão `admin123`
  (alterar via `.env`: `APP_ADMIN_EMAIL` / `APP_ADMIN_PASSWORD`). A seed `0002`
  força a **troca de senha no primeiro acesso** enquanto a senha padrão estiver
  ativa.
- Fontes de conhecimento `unimestre_oficial` (official) e `base_interna` (internal).
- Providers `openrouter` e `nvidia` (inativos, aguardando configuração na fase 5).
- Configurações padrão em `system_settings` (nome do app, prompt de sistema e
  parâmetros de RAG).

## 7. Execução

Com Apache ativo, acesse:

```
http://localhost:8182/assistente-unimestre/
```

Páginas disponíveis:

- `/` — página inicial com status do ambiente
- `/diagnostico` — diagnóstico completo (HTML; somente admin, `diagnostic.view`)
- `/api/diagnostico` — diagnóstico em JSON (somente admin)
- `/login` — autenticação
- `/dashboard` — painel (autenticado)
- `/alterar-senha` — troca de senha (obrigatória no primeiro acesso)
- `/usuarios` — gestão de usuários (somente `admin`)
- `/knowledge` — base de conhecimento consultável (admin + técnica)
- `/knowledge/{id}` — detalhe do documento
- `/admin/knowledge/import` — importação manual (somente `admin`)
- `/admin/knowledge/{id}/edit` — edição de documento (somente `admin`)
- `/solutions` — soluções internas (admin + técnica)
- `/solutions/novo` e `/solutions/{id}/editar` — cadastro/edição (admin + técnica)
- `/admin/search-test` — ferramenta administrativa de recuperação RAG local
  (somente `admin`)
- `/admin/ai` — painel de integração com IA: configuração de provider, modelo,
  parâmetros e API Key criptografada (somente `admin`)
- `/admin/ai/playground` — teste manual do provider ativo (somente `admin`)
- `/chat` — assistente RAG: pergunta e resposta com fontes citadas (autenticado)
- `/chat/{id}` — conversa existente (somente o dono da conversa; outro usuário → 404)
- `/chat/{id}/salvar-solucao` — salvar resposta da IA como solução interna
  (permissão `solutions.manage`)
- `/history` — histórico de conversas do usuário (autenticado)
- `POST /api/chat` — endpoint JSON do chat (validado, com CSRF e rate limit)
- `POST /api/chat/{id}/feedback` — feedback util/inutil na resposta

## 8. Segurança (Fase 2)

- Senhas com `password_hash` (bcrypt) e verificação com `password_verify`.
- Troca de senha **obrigatória** no primeiro acesso (`must_change_password=1`);
  o painel fica bloqueado até a troca.
- CSRF em **todos** os POST (resposta `419 Page Expired` sem token válido),
  sessões com `session_regenerate_id` no login, cookies `HttpOnly`/`SameSite=Lax`,
  idle timeout de sessão.
- Perfis `admin` / `tecnico` / `usuario` e matriz de permissões centralizada em
  `config/permissions.php` (`admin` tem `*`; `tecnico`/`usuario` só acesso
  básico). Acesso negado → `403`.
- Rate limiting de login: `AUTH_MAX_ATTEMPTS` falhas por janela
  (`AUTH_ATTEMPT_WINDOW_MINUTES`) por e-mail e por IP; ao exceder, a conta é
  bloqueada por `AUTH_LOCKOUT_MINUTES` (`locked_until`).
- Proteção do **último administrador ativo**: não pode ser desativado, excluído
  nem rebaixado via gestão de usuários.
- Auditoria em `audit_logs` (login, falhas, bloqueios, CRUD, acesso negado) e
  eventos de segurança em `storage/logs/security.log`.

Variáveis de ambiente relacionadas: `AUTH_MAX_ATTEMPTS`, `AUTH_LOCKOUT_MINUTES`,
`AUTH_ATTEMPT_WINDOW_MINUTES`, `AUTH_PASSWORD_MIN_LENGTH`.

## 9. Testes

```
php tests/run.php     # suíte CLI (146 testes)
php tests/http.php    # cenários HTTP reais via Apache (59 cenários)
```

A suíte CLI cobre a Fase 1 (`.env`, config, autoload, PDO, migrations, tabelas,
FULLTEXT, seeds, logs, rotas), a Fase 2 (colunas de segurança, tabela
`login_attempts`, hash de senha, CSRF, flashes, permissões, params/middleware de
rota, estados de `attempt`, rate limit, lock, proteção do último admin,
auditoria), a Fase 3 (categorias/origens centralizadas, limites de upload,
normalização de texto e URL, hash SHA-256, slug único, importação manual e via
arquivo, bloqueios de duplicata/conflito de versão/duplicidade de conteúdo,
validações, troca de origem com auditoria, status, filtros/paginação, contagens
agregadas, soluções internas com tags normalizadas e auditoria, escape de saída
com `e()`) e a Fase 4 (configurações de chunking/retrieval, estimativa de tokens,
quebra por títulos/listas com sobreposição, normalização e stopwords,
singular/acentos, FULLTEXT real, importação encadeada e reindexação
transacional, ranking por pesos, boost de fonte oficial, documento inativo,
termos curtos (EAD/AVA/CPF/RA), ausência de resultados, `top_k`, solução interna
na busca, injeção SQL, determinismo e desempenho com 1.000 documentos) e a
Fase 5 (criptografia AES-256-GCM com `v1:` — roundtrip, tamper, chave errada e
falha segura sem `APP_ENCRYPTION_KEY`; DTO de resposta; mapeamento de erros
401/429/modelo ausente/timeout/DNS/SSL/JSON inválido; retry apenas em erros
transitórios; bloqueio de base URL inválida; validação de configuração;
ativação sem chave bloqueada; salvar sem `APP_ENCRYPTION_KEY` bloqueado; teste
de conexão e chat com transporte fake; chave de teste não persiste em logs nem
auditoria e provider restaurado ao original; 200 com campo error mapeado para provider_error, resposta apenas com raciocínio vira easoning_only; finish length com conteudo vazio vira 	runcated_response e conteudo parcial mantem sucesso com metadado 	runcated). Os testes HTTP validam os fluxos
completos contra o Apache na porta 8182 — incluindo a ferramenta
`/admin/search-test` (acesso, busca vazia, resultados com fonte oficial, escape
de conteúdo, mensagem de vazio, 403 para técnico/usuário, CSRF e ordenação por
relevância) e a Fase 5 de IA (tela de configuração sem expor a chave, playground
com e sem provider ativo, pergunta vazia, ativação sem chave bloqueada, chave
criptografada com `v1:` e nunca devolvida ao navegador, teste de conexão apenas
com provider ativo, alternância de status, auditoria `ai.*`, 403 para
técnico/usuário e CSRF 419) — e restauram o ambiente ao final (o admin volta a
`admin123` com troca forçada; documentos, soluções e providers de teste são
removidos/restaurados).

A Fase 6 (chat + RAG) é coberta **sem usar rede nem créditos**: a suíte CLI
`tests/phase6.php` usa transporte fake (14 testes) e a suíte HTTP valida os
fluxos reais sobre o Apache mantendo o provider **desativado** durante os testes
de chat — afirmando `gateway_state=provider_unavailable`, `ai_called=false` e
fontes reais do banco (o caminho "contexto → IA chamada → resposta" é validado
na CLI). Os cenários HTTP cobrem CSRF (419 sem token), validação (422 para
pergunta vazia/`question_required` e longa/`question_too_long`), sem contexto
(`no_context` + mensagem local), com contexto (fontes reais + pergunta
persistida), rate limit (10 permitidas, 11ª → 429 `rate_limited` com usuário
dedicado), ownership (`GET /chat/{id}` e feedback alheios → 404), XSS (conteúdo
`<script>` do banco renderizado escapado na view) e permissões
(`/chat/{id}/salvar-solucao`: sem `solutions.manage` → 403, com permissão →
formulário "Salvar como solução interna").

Sobre as fontes (URL segura + cards): toda URL exibida passa por
`App\Support\SourceUrl` (http/https válidas; bloqueia `*.example`, `.example`
e títulos de fixtures `F4-/F5-/F6-`) — aplicada em `presentSources()`, na
thread e na visualização de conhecimento, com guard JS `isSafeSourceUrl` no
chat. As respostas renderizam um card por fonte: badge (`OFICIAL UNIMESTRE`/
`PROCEDIMENTO INTERNO`), título linkado apenas quando a URL é segura,
categoria, trecho, relevância e botão "Abrir fonte". O JSON do chat também
expõe `is_tech_support` e o diagnóstico granular
`gateway_state` (`provider_unavailable` | `timeout` | `rate_limited` |
`provider_failure`) + `error_code` (`http_401`, `http_404_model`, etc.),
mostrado apenas a admin/técnico como "Diagnóstico técnico". O isolamento da
suíte HTTPS é garantido por `httpSafeCleanup()` registrado via
`register_shutdown_function` (restaura provider/chave/modelo, admin
`admin123` + troca forçada, buckets de rate limit, auditoria e remove
usuários/docs/conversas de teste mesmo em caso de aborto).

Saída esperada:

```
FASES 1 + 2 + 3 + 4 + 5 + 6 — SUÍTE DE TESTES
...
TOTAL: 146 | PASS: 146 | FAIL: 0   (teste de Fase 4 corrigido: verifica estrutura/parametrização em vez de assumir mensagem)

FASE 2 + 3 + 4 + 5 + 6 — TESTES HTTP (Apache 8182)
...
TOTAL: 59 | PASS: 59 | FAIL: 0
```

## 10. Base de conhecimento (Fase 3)

- Origens mantidas **visualmente separadas**: badges `OFICIAL UNIMESTRE`
  (fontes `official`) e `PROCEDIMENTO INTERNO` (fontes `internal`).
- Importação **manual** por formulário (título, URL, categoria, subcategoria,
  conteúdo, origem, status) ou por arquivo `.txt`/`.md` (máximo 2 MB,
  configurável em `config/knowledge.php`; `cadastro via fileinfo` rejeita
  binários e não-UTF-8). PDF/crawler não fazem parte desta fase.
- Normalização de texto (CRLF→LF, remoção de caracteres de controle, colapso de
  linhas em branco) e `content_hash` SHA-256 para duplicidade.
- Regras de duplicidade: mesma URL + mesmo conteúdo → bloqueio com aviso; mesma
  URL + conteúdo diferente → **conflito de versão** (não sobrescreve); mesmo
  conteúdo + mesma origem → **duplicidade de conteúdo**; cross-origem permitido.
- Status `active`/`inactive` (prefere desativação a exclusão) e contadores
  agregados por consulta (`counts()`).
- Categorias centralizadas em `config/knowledge.php` (Acadêmico, Financeiro,
  Portal, Relatórios, Configurações, Biblioteca, EAD, Integrações, Outros).
- Busca simples com filtros (categoria/subcategoria/origem/status) e paginação
  de 20 itens — **sem** ranking RAG (Fase 4).
- Soluções internas (`internal_solutions`) com problema/solução, tags
  normalizadas (minúsculas, deduplicadas, ordenadas) e status.
- Auditoria de todos os eventos de conhecimento/solução
  (`knowledge.document.*`, `solution.*`), incluindo tentativa de duplicidade e
  mudança de origem.

Permissões da Fase 3 (`config/permissions.php`):

| Permissão | admin | técnica | usuário |
|---|---|---|---|
| `knowledge.view` | ✔ | ✔ | ✘ |
| `documents.manage` | ✔ | ✘ | ✘ |
| `solutions.view` | ✔ | ✔ | ✘ |
| `solutions.manage` | ✔ | ✔ | ✘ |

## 11. Busca RAG local (Fase 4)

- Chunking de `knowledge_documents` em `knowledge_chunks` em importação/edição:
  tamanho máximo de tokens, sobreposição de caracteres e quebra preservando
  títulos e listas — parâmetros em `config/knowledge.php`
  (`chunking.target_tokens` 450, `overlap_tokens` 80, `max_tokens` 650).
- Reindexação **transacional**: substitui os chunks da versão anterior ao
  importar/editar; exclusão em cascata quando o documento é removido.
- Busca híbrida **100% local** (`app/Services/RAG/`):
  - `SearchQueryService` — normalização (caixa baixa, remoção de acentos),
    stopwords configuráveis e variantes no singular;
  - `MySqlFullTextStrategy` — FULLTEXT em `content/search_text` com fallback
    `LIKE` para termos curtos (1–2 letras, ex.: RA/NF) e plurais;
  - `InternalSolutionRetrievalStrategy` — soluções internas concorrem na mesma
    busca, com badge `PROCEDIMENTO INTERNO`.
- Ranking por pesos configuráveis (`fulltext`, `title`, `category`,
  `subcategory`, `tags`, `content`, `exact`), boost de fonte oficial, threshold
  mínimo de relevância e retorno `top_k` — tudo em `config/knowledge.php`
  (`retrieval.*`).
- Ferramenta `/admin/search-test` (permissão `search.test`, apenas `admin`):
  exibe pergunta normalizada, termos, estratégias utilizadas, candidatos,
  duração, trechos, fonte e o breakdown do score; saída sempre escapada com
  `e()`.
- Logs de busca em `storage/logs/retrieval.log` (sem a pergunta original) e
  teste de desempenho com 1.000 documentos sintéticos em `perf4.log`
  (média ~34 ms, pior caso ~46 ms neste ambiente).

## 12. Integração com IA (Fase 5)

- Camada de IA em `app/Services/AI/`: interface `AIProviderInterface`,
  transporte HTTP próprio (`Http/CurlTransport`, sem Composer), provedor
  `OpenRouterProvider` (`POST /api/v1/chat/completions`, headers `Authorization:
  Bearer`, `HTTP-Referer` e `X-OpenRouter-Title`) e `AIService` que orquestra
  configuração, teste de conexão e chat.
- API Key armazenada **criptografada** em `ai_providers.api_key_encrypted`
  (OpenSSL **AES-256-GCM**, formato versionado `v1:nonce|ciphertext|tag` via
  `Security/EncryptionService`). A chave **nunca** aparece em HTML, JavaScript,
  logs, auditoria ou respostas — apenas o indicador `has_key` e a máscara
  `sk-••••••••••`.
- A criptografia usa `APP_ENCRYPTION_KEY` (64 hex, gerada na seção 4). Sem ela,
  salvar/descriptografar credenciais é bloqueado com falha segura. `openssl` com
  `aes-256-gcm` é obrigatório.
- Proteção SSRF: o payload usa apenas a `base_url` permitida em
  `config/app.php` (`ai.allowed_base_urls`), restrita a HTTPS e à lista
  conhecida; URLs do formulário não são aceitas. TLS sempre verificado
  (`CURLOPT_SSL_VERIFYPEER/VERIFYHOST`).
- Tratamento de erros específico do provedor: `401` (aperitvo recusado), `429`
  (limite/créditos), modelo ausente, timeout, problemas de rede/DNS/TLS e JSON
  inválido. Retry automático (máximo 1) apenas para erros transitórios — nunca
  em `400/401/403`.
- Painel `/admin/ai` (permissões `ai.view`/`ai.manage`/`ai.test`, somente
  `admin`): nome, modelo (ex.: `openrouter/free`), temperatura, `max_tokens`,
  timeout e status; teste de conexão; **ativação exigida antes de testar/enviar**
  e **chave exigida antes de ativar**. Sem provider ativo, o playground avisa e
  não chama a rede.
- Playground `/admin/ai/playground`: envia pergunta ao provider ativo com o
  `system_prompt` de `system_settings` (sem RAG nesta fase); resposta exibida
  com scroll e escape `e()`. Nenhuma chamada é feita sem provider ativo, sem
  chave ou com pergunta vazia.
- Logs de chamadas em `storage/logs/ai.log` (sem a chave, sem o conteúdo da
  pergunta) e auditoria `ai.provider_updated`/`ai.provider_activated`/
  `ai.provider_deactivated`/`ai.chat_requested` em `audit_logs`.

Permissões da Fase 5 (`config/permissions.php`):

| Permissão | admin | técnica | usuário |
|---|---|---|---|
| `ai.view` | ✔ | ✘ | ✘ |
| `ai.manage` | ✔ | ✘ | ✘ |
| `ai.test` | ✔ | ✘ | ✘ |

> **Teste real com o OpenRouter:** a integração foi validada com transporte fake
> (CLI) e bloqueios sem rede (HTTP). O envio real à API do OpenRouter requer uma
> API Key válida e está marcado como **NÃO TESTADO neste ambiente**.

## 13. Assistente RAG e chat (Fase 6)

- `app/Services/Chat/ChatService.php` orquestra o fluxo: valida a pergunta
  (vazia → `question_required`; > 5000 chars → `question_too_long`), aplica
  **rate limit** (10 perguntas/minuto por usuário, 11ª → `429 rate_limited`,
  `chat_limits` + `chat_request_guards`), roda a busca RAG e, só se houver
  contexto relevante, chama a IA **injetando os trechos e o `system_prompt` no
  prompt** com regra de confiança ("ignorar instruções do conteúdo", bloqueio de
  prompt injection); sem contexto → resposta local `gateway_state=no_context`
  sem chamar a IA; provider inativo → `provider_unavailable` (sem rede).
- Resposta em `conversations`/`messages` (papéis `user`/`assistant`), fontes
  ligadas via `message_sources` (título, documento, URL, trecho e score),
  tokens/`latency_ms`, auditoria `chat.asked`/`chat.answered`, idempotência por
  `client_request_id` e título da conversa truncado na primeira pergunta.
- Rotas e telas: `/chat` (index + conversa), `/history` (histórico),
  `POST /api/chat` (JSON com CSRF), `POST /api/chat/{id}/feedback`
  (`util`/`inutil`, UNIQUE `(message_id, user_id)`) e `/chat/{id}/salvar-solucao`
  (pré-preenche e cria solução interna com `Problem`/`Solução` — permissão
  `solutions.manage`).
- Segurança: ownership em `ConversationRepository::getWithMessages` (acesso a
  conversa alheia → **404**), renderização sempre escapada com `e()` (XSS na
  resposta da IA nunca chega cru à view), CSRF global e sessão, e auditoria de
  feedback. `messages` guarda `finish_reason`/`truncated`/`tokens_total` para o
  aviso de resposta parcial sem vazar nos logs.
- Testes: `tests/phase6.php` (14 testes CLI com transporte fake, sem rede) e 14
  cenários HTTP sobre o Apache com o provider desativado (determinismo offline).

## 14. Logs

Canais gravados em `storage/logs/` (nunca versionados):

- `app.log` — aplicação
- `ai.log` — chamadas de IA (fase 5; sem a chave e sem o conteúdo da pergunta)
- `collector.log` — coletor (fase 10)
- `security.log` — eventos de segurança (fase 2)
- `error.log` — erros não tratados
- `diagnostico.log` — testes do diagnóstico
- `retrieval.log` — buscas executadas (fase 4; sem a pergunta original)

O logger sanitiza automaticamente valores sensíveis (`api_key`, `password`,
`token`, `secret`, etc.) antes de gravar.

**Retenção (`LOG_DAYS_TO_KEEP`, padrão 30):** no máximo uma vez por dia, um
canal cuja última escrita foi em dia anterior é renomeado para
`{canal}-AAAA-MM-DD.log`; arquivos arquivados mais antigos que a retenção são
removidos. Só arquivos com esses padrões de nome, diretamente em
`storage/logs/`, são tocados.

**Debug:** em desenvolvimento `APP_DEBUG=true` é permitido. Com
`APP_ENV=production` o debug é sempre desligado pelo código, mesmo com
`APP_DEBUG=true`.

**Acesso HTTP:** o `.htaccess` da raiz bloqueia (403) `.env`, dotfiles,
`app/`, `bootstrap/`, `config/`, `database/`, `resources/`, `routes/`,
`storage/`, `tests/`, `README*`, `RELATORIO*` e `*.md/*.log`. Em produção,
prefira apontar o DocumentRoot para `public/`.

## 15. API Key dos providers

A API Key é configurada no painel `/admin/ai` e fica **criptografada no banco**
(`ai_providers.api_key_encrypted`, OpenSSL AES-256-GCM com `APP_ENCRYPTION_KEY`)
e é lida apenas no servidor — nunca em JavaScript, HTML, logs ou no repositório.
A tela mostra apenas a máscara `sk-••••••••••`; preencher o campo novamente
substitui a chave existente.

Configuração para uso real:

1. Acesse `/admin/ai` (com o `admin`).
2. Defina o modelo (ex.: `openrouter/free`) e a API Key (obtida em
   <https://openrouter.ai/keys>).
3. Ative o provider e use o **teste de conexão** antes de usar o playground.
4. Para trocar de chave, basta preencher o campo novamente com a nova chave.

## 16. Troubleshooting

**MySQL não inicia (Fase de ambiente)**
Se o MariaDB abortar ao iniciar, verifique `C:\xampp\mysql\data\mysql_error.log`.
Um erro comum é `Table '.\mysql\db' is marked as crashed`. Repare com:

```
C:\xampp\mysql\bin\aria_chk.exe --recover C:\xampp\mysql\data\mysql\db.MAI
```

**Acesso 403 em http://localhost:<porta>/assistente-unimestre/**
A raiz do projeto precisa de um `index.php` (existe). Verifique se o
`mod_rewrite` está ativo e se `AllowOverride All` está configurado para o
`htdocs` no `httpd.conf` do Apache.

**Erro "There is no active transaction" nas migrations**
DDL (CREATE TABLE) no MariaDB causa commit implícito — migrations não são
executadas dentro de transação (comportamento esperado do runner).

**Acentos exibidos de forma estranha no prompt/console**
É limitação de codepage do console do Windows; os dados são armazenados em
`utf8mb4` (verifique com phpMyAdmin).

## 17. Rotas de evolução (próximas fases)

0. Ferramentas e ambiente (concluído)
1. Fundação do projeto (concluído)
2. Autenticação, usuários, perfis e segurança (concluído)
3. Base de conhecimento e importação manual (concluído)
4. Chunking e busca MySQL com ranking (concluído)
5. Camada de IA multi-provider + OpenRouter (concluído)
6. RAG completo + chat com fontes (concluído)
7. Chat + streaming (Fase 7)
8. NVIDIA como segundo provider (Fase 8)
9. Soluções internas — aprimoramento (Fase 9)
10. Coleta/sincronização da documentação (Fase 10)
11. Dashboard, logs, auditoria e métricas (Fase 11)
12. Hardening e preparação para uso real (Fase 12)
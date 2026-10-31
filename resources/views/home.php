<?php
$primaryUrl = !empty($isLoggedIn) ? app_url('assistente') : app_url('login');
$primaryLabel = !empty($isLoggedIn) ? 'Abrir o assistente' : 'Entrar no sistema';
?>
<div class="home">
    <section class="home-hero">
        <p class="home-kicker">Suporte técnico do UNIMESTRE</p>
        <h1 class="home-title">Encontre a resposta certa sem começar o atendimento do zero</h1>
        <p class="home-lead">
            O assistente consulta a base de conhecimento do UNIMESTRE, investiga o problema junto com o técnico
            e mostra de onde veio cada informação.
        </p>
        <a class="btn btn-primary btn-lg" href="<?php echo e($primaryUrl); ?>"><?php echo e($primaryLabel); ?></a>
    </section>

    <section class="home-section" aria-labelledby="home-what">
        <h2 id="home-what" class="home-heading">O que o sistema faz</h2>
        <div class="row g-3">
            <div class="col-md-4">
                <div class="card app-card h-100"><div class="card-body">
                    <h3 class="h6 fw-semibold">Responde com fontes</h3>
                    <p class="text-muted small mb-0">Para dúvidas e procedimentos, responde com base nos documentos cadastrados e indica as fontes consultadas.</p>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card app-card h-100"><div class="card-body">
                    <h3 class="h6 fw-semibold">Ajuda a investigar problemas</h3>
                    <p class="text-muted small mb-0">Levanta possíveis causas e faz uma pergunta de cada vez, atualizando as hipóteses conforme as respostas.</p>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card app-card h-100"><div class="card-body">
                    <h3 class="h6 fw-semibold">Guarda o que a equipe aprendeu</h3>
                    <p class="text-muted small mb-0">Depois de revisados por um técnico, casos resolvidos e procedimentos internos passam a ajudar nos próximos atendimentos.</p>
                </div></div>
            </div>
        </div>
    </section>

    <section class="home-section" aria-labelledby="home-how">
        <h2 id="home-how" class="home-heading">Como funciona um atendimento</h2>
        <ol class="home-steps">
            <li><strong>Descreva o problema.</strong> Por exemplo: “o boleto do aluno não aparece no Portal”.</li>
            <li><strong>Responda às verificações.</strong> O assistente pede uma informação por vez e não repete o que já foi respondido.</li>
            <li><strong>Acompanhe as hipóteses.</strong> Cada possível causa mostra sua situação e as evidências registradas.</li>
            <li><strong>Conclua o caso.</strong> Registre a solução ou, se faltar evidência, prepare um resumo para escalar ao suporte.</li>
        </ol>
    </section>

    <section class="home-section" aria-labelledby="home-sources">
        <h2 id="home-sources" class="home-heading">Você sempre sabe de onde veio a informação</h2>
        <div class="home-badges">
            <span class="chat-badge chat-badge-official">📘 OFICIAL UNIMESTRE</span>
            <span class="chat-badge chat-badge-internal">🛠️ PROCEDIMENTO INTERNO</span>
            <span class="chat-badge chat-badge-vendor">🧾 SUPORTE UNIMESTRE</span>
            <span class="chat-badge chat-badge-case">🧩 CASO RESOLVIDO</span>
            <span class="chat-badge chat-badge-hypothesis">🔎 HIPÓTESE DA IA</span>
        </div>
        <p class="text-muted small mt-2 mb-0">Hipóteses da IA ficam identificadas como hipóteses e nunca aparecem como documentação.</p>
    </section>

    <section class="home-note" aria-labelledby="home-limit">
        <h2 id="home-limit" class="h6 fw-semibold">O sistema é somente assistivo</h2>
        <p class="small mb-0">Ele não altera dados nem executa ações no UNIMESTRE, e não abre chamados sozinho. As decisões continuam com o técnico.</p>
    </section>
</div>

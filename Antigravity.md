# Antigravity

## Objetivo

Este arquivo define como o Antigravity e o Codex devem se comportar neste projeto quando a tarefa envolver o uso do Codex app, da extensao IDE do Codex ou do fluxo de trabalho local no aplicativo.

O objetivo aqui nao e configurar o servidor do projeto. O objetivo e usar corretamente o aplicativo do Codex, com informacoes atuais sobre login, projetos, modo local e recursos principais.

## Fonte de verdade para este contexto

Considerar como base as informacoes mais recentes fornecidas pelo usuario sobre o Codex app.

Pontos confirmados:

- o Codex app e uma experiencia desktop focada para trabalhar com threads em paralelo;
- o app inclui suporte nativo a worktrees, automacoes e funcoes de Git;
- o Codex app esta disponivel para macOS e Windows;
- o Linux nao aparece como plataforma suportada para o app desktop neste material; existe apenas lista de notificacao;
- ChatGPT Plus, Pro, Business, Edu e Enterprise incluem Codex;
- o login pode ser feito com conta ChatGPT ou com API key da OpenAI;
- ao usar API key, alguns recursos podem nao estar disponiveis, como cloud threads;
- depois do login, o usuario deve escolher uma pasta de projeto;
- para trabalhar na propria maquina, o modo correto e `Local`.

## Como orientar o uso do app

Quando a solicitacao estiver relacionada ao aplicativo do Codex, orientar nesta sequencia:

1. confirmar se o usuario esta no app desktop ou na extensao IDE;
2. confirmar se o projeto correto foi aberto;
3. confirmar se o ambiente esta em `Local` quando a execucao deve acontecer na maquina do usuario;
4. confirmar se o login foi feito com ChatGPT ou API key;
5. se houver limitacao de recurso, verificar se ela pode ser explicada pelo modo de login.

## Fluxo inicial esperado

Ao explicar ou configurar o app, assumir este fluxo base:

1. baixar e instalar o Codex app para macOS ou Windows;
2. abrir o app e entrar com conta ChatGPT ou API key;
3. selecionar a pasta do projeto;
4. escolher `Local` para trabalhar na maquina;
5. enviar a primeira mensagem para iniciar a thread.

## Recursos que devem ser considerados atuais

Ao responder sobre capacidades do Codex app, tratar estes recursos como disponiveis no material fornecido:

- multitarefa entre projetos;
- worktrees integradas;
- remote connections;
- computer use;
- review e envio de mudancas;
- terminal e actions;
- navegador embutido;
- extensao para Chrome;
- geracao e edicao de imagens;
- automations;
- skills;
- sidebar e artifacts;
- plugins;
- sincronizacao com a IDE Extension.

## Regras de resposta para este contexto

Quando o assunto for o aplicativo do Codex:

- nao afirmar suporte desktop para Linux com base neste material;
- nao tratar cloud threads como garantidas quando o login for por API key;
- diferenciar claramente app desktop, CLI e IDE Extension;
- priorizar instrucoes de uso no aplicativo, nao no servidor;
- se houver conflito entre configuracao do projeto e configuracao do app, verificar primeiro a configuracao global do usuario.

## Preferencias praticas para este ambiente

Neste host, o aplicativo esta apontando para o executavel local do Codex CLI.

Ao configurar ou revisar o ambiente do app, priorizar:

- interface em portugues quando o usuario estiver usando o IDE em `pt-BR`;
- abertura direta da sidebar do Codex;
- projeto aberto no contexto correto;
- modo local para tarefas no proprio host;
- uso de worktrees apenas quando o projeto realmente estiver em um repositorio Git.

## O que evitar

Evitar:

- tratar configuracao do app como se fosse configuracao do servidor do projeto;
- assumir que toda funcionalidade do app tambem existe na CLI isolada;
- recomendar cloud threads sem verificar o tipo de autenticacao;
- afirmar que a documentacao antiga de Firecrawl define o comportamento do Codex app;
- confundir "configurar a IDE" com editar apenas arquivos da aplicacao web.

## Entregaveis esperados ao ajudar com o app

Quando a ajuda for sobre o Antigravity/Codex app, a resposta deve incluir, quando fizer sentido:

- onde a configuracao correta fica;
- qual ajuste foi feito no aplicativo;
- se o ajuste exige reiniciar a IDE ou a extensao;
- quais recursos ficam disponiveis apos o ajuste;
- quais limitacoes continuam dependendo do tipo de login ou da plataforma.

## Referencias funcionais citadas pelo usuario

Links relevantes do material fornecido:

- `https://developers.openai.com/codex/pricing`
- `https://developers.openai.com/codex/prompting#threads`
- `https://developers.openai.com/codex/use-cases`
- `https://developers.openai.com/codex/learn/best-practices`
- `https://developers.openai.com/codex/app/troubleshooting`

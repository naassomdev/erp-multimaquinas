<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Helpers\ClienteHelper;
use App\Queue\DatabaseQueue;

/**
 * Enfileira notificações ao cliente (WhatsApp / e-mail) sem bloquear o request.
 *
 * O envio efetivo acontece no worker via NotificarClienteJob — aqui só montamos
 * o payload e jogamos na fila. Se nem a fila estiver acessível (DB caiu),
 * registramos no log e seguimos: nunca derrubar o fluxo principal por causa
 * de um nice-to-have.
 */
final class NotificationService
{
    public function __construct(
        private readonly ?DatabaseQueue $queue = null,
    ) {}

    private function queue(): DatabaseQueue
    {
        return $this->queue ?? new DatabaseQueue(Database::pdo());
    }

    /**
     * Notifica o cliente de que a OS foi aberta.
     *
     * @param string $osId
     * @param array{nome:string, telefone:?string, email:?string, cliente_id:?int, link_termo:?string} $cliente
     * @param array<int, array{nome:string}>                                       $equipamentos
     * @return ?int ID do job enfileirado, ou null se não havia canal disponível.
     */
    public function notificarOsCriada(string $osId, array $cliente, array $equipamentos): ?int
    {
        // 10F-2: se contato_telefone preenchido, usa ele; senão cai no helper de prioridade.
        $contatoTel = trim((string)($cliente['contato_telefone'] ?? ''));
        if ($contatoTel !== '') {
            $telLimpo = preg_replace('/\D/', '', $contatoTel) ?? '';
            $telefone = mb_strlen($telLimpo) >= 10
                ? (str_starts_with($telLimpo, '55') ? $telLimpo : '55' . $telLimpo)
                : (ClienteHelper::telefoneParaWhatsapp($cliente) ?? '');
        } else {
            // 10F-1: prioridade celular → telefone2 → fone → telefone.
            $telefone = ClienteHelper::telefoneParaWhatsapp($cliente) ?? '';
        }
        $email = trim((string)($cliente['email'] ?? ''));

        if ($telefone === '' && $email === '') {
            return null;
        }

        // Filtra equipamentos com nome preenchido; preserva objeto completo para a mensagem.
        $equipsFiltrados = array_values(array_filter(
            $equipamentos,
            static fn($e) => trim((string)($e['nome'] ?? '')) !== ''
        ));
        // Lista simples de nomes para o payload do job (e-mail fallback etc.)
        $listaEquip = array_map(static fn($e) => trim((string)($e['nome'] ?? '')), $equipsFiltrados);

        $linkTermo = trim((string)($cliente['link_termo'] ?? ''));

        // 10F-2: contato_nome tem prioridade na saudação.
        $contatoNome  = trim((string)($cliente['contato_nome'] ?? ''));
        $nomeDisplay  = $contatoNome !== ''
            ? ClienteHelper::nomeParaMensagem(['nome' => $contatoNome, 'nome_fantasia' => ''])
            : ClienteHelper::nomeParaMensagem($cliente);

        // Se contato for diferente da empresa, passar nome da empresa para a mensagem.
        $nomeEmpresa = '';
        if ($contatoNome !== '') {
            $nomeEmpresa = ClienteHelper::nomeParaMensagem($cliente);
            if ($nomeEmpresa === $nomeDisplay) {
                $nomeEmpresa = ''; // mesma pessoa — não repetir
            }
        }

        $mensagem = $this->montarMensagem($osId, $nomeDisplay, $equipsFiltrados, $linkTermo, $nomeEmpresa);

        $payload = [
            'os_id'        => $osId,
            'cliente_id'   => $cliente['cliente_id'] ?? null,
            'cliente_nome' => $nomeDisplay,
            'telefone'     => $telefone,
            'email'        => $email,
            'mensagem'     => $mensagem,
            'equipamentos' => $listaEquip,
            'link_termo'   => $linkTermo,
            'evento'       => 'os_criada',
        ];

        try {
            return $this->queue()->enqueue('notificar_cliente', $payload);
        } catch (\Throwable $e) {
            error_log('[NotificationService] falha ao enfileirar: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Monta mensagem WhatsApp de abertura da OS.
     *
     * @param array<int, array<string, mixed>> $equipamentos Objetos completos (com fabricante, modelo, etc.)
     */
    private function montarMensagem(
        string $osId,
        string $nome,
        array  $equipamentos,
        string $linkTermo  = '',
        string $nomeEmpresa = '',
    ): string {
        // 10F-4: saudação por horário centralizada no ClienteHelper.
        $saudacao     = ClienteHelper::saudacaoPorHorario();
        $primeiroNome = trim(strtok($nome ?: '', ' ') ?: '');
        $cumprimento  = $primeiroNome !== ''
            ? "{$saudacao}, *{$primeiroNome}*, tudo bem?"
            : "{$saudacao}!";

        $partes = [$cumprimento, ''];

        // Se contato for funcionário de empresa, menciona o cliente/empresa
        if ($nomeEmpresa !== '') {
            $partes[] = "Cliente: *{$nomeEmpresa}*";
            $partes[] = '';
        }

        $partes[] = "Sua Ordem de Serviço *#{$osId}* foi registrada na Multimáquinas 🔧";
        $partes[] = '';
        $partes[] = 'Equipamento(s):';

        if (empty($equipamentos)) {
            $partes[] = '• (nenhum equipamento)';
        } else {
            foreach ($equipamentos as $eq) {
                $nome_eq    = trim((string)($eq['nome']       ?? ''));
                $fabricante = trim((string)($eq['fabricante'] ?? ''));
                $modelo     = trim((string)($eq['modelo']     ?? ''));
                $serie      = trim((string)($eq['serie']      ?? ''));
                $voltagem   = trim((string)($eq['voltagem']   ?? ''));

                $partes[] = "• {$nome_eq}";

                $detalhes = [];
                if ($fabricante !== '') $detalhes[] = "  Marca: {$fabricante}";
                if ($modelo     !== '') $detalhes[] = "  Modelo: {$modelo}";
                if ($serie      !== '') $detalhes[] = "  Série: {$serie}";
                if ($voltagem   !== '') $detalhes[] = "  Voltagem: {$voltagem}";

                foreach ($detalhes as $detalhe) {
                    $partes[] = $detalhe;
                }
            }
        }

        // Link do termo de responsabilidade
        if ($linkTermo !== '') {
            $partes[] = '';
            $partes[] = '📋 *Leia e aceite nosso Termo de Responsabilidade:*';
            $partes[] = $linkTermo;
        }

        $partes[] = '';
        $partes[] = 'Assim que o orçamento estiver pronto, avisaremos.';
        $partes[] = 'Obrigado pela confiança! — Multimáquinas';

        return implode("\n", $partes);
    }
}


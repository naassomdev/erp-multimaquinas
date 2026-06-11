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
     * Notifica o cliente de que UM equipamento específico ficou pronto.
     *
     * Disparado quando o técnico marca o equipamento como "pronto". Como um
     * mesmo cliente pode ter vários equipamentos na oficina, a mensagem deixa
     * claro que o aviso é por item e que os demais podem seguir em manutenção.
     *
     * @param array{nome:string, telefone:?string, email:?string, celular:?string, fone:?string, telefone2:?string, contato_nome:?string, contato_telefone:?string, nome_fantasia:?string, cliente_id:?int} $cliente
     * @param string     $equipamentoNome Nome do equipamento finalizado.
     * @param float|null $valorTotal      Total do orçamento aprovado; null/<=0 → "Sem custo (garantia)".
     * @return ?int ID do job enfileirado, ou null se não havia canal/telefone.
     */
    public function notificarEquipamentoPronto(
        string $osId,
        array  $cliente,
        string $equipamentoNome,
        ?float $valorTotal,
    ): ?int {
        // Resolução de telefone idêntica à de notificarOsCriada:
        // contato_telefone tem prioridade; senão helper (celular → telefone2 → fone → telefone).
        $contatoTel = trim((string)($cliente['contato_telefone'] ?? ''));
        if ($contatoTel !== '') {
            $telLimpo = preg_replace('/\D/', '', $contatoTel) ?? '';
            $telefone = mb_strlen($telLimpo) >= 10
                ? (str_starts_with($telLimpo, '55') ? $telLimpo : '55' . $telLimpo)
                : (ClienteHelper::telefoneParaWhatsapp($cliente) ?? '');
        } else {
            $telefone = ClienteHelper::telefoneParaWhatsapp($cliente) ?? '';
        }
        $email = trim((string)($cliente['email'] ?? ''));

        if ($telefone === '' && $email === '') {
            return null;
        }

        // contato_nome tem prioridade na saudação (mesmo critério da abertura).
        $contatoNome = trim((string)($cliente['contato_nome'] ?? ''));
        $nomeDisplay = $contatoNome !== ''
            ? ClienteHelper::nomeParaMensagem(['nome' => $contatoNome, 'nome_fantasia' => ''])
            : ClienteHelper::nomeParaMensagem($cliente);

        $mensagem = $this->montarMensagemPronto($nomeDisplay, $equipamentoNome, $valorTotal);

        $payload = [
            'os_id'        => $osId,
            'cliente_id'   => $cliente['cliente_id'] ?? null,
            'cliente_nome' => $nomeDisplay,
            'telefone'     => $telefone,
            'email'        => $email,
            'mensagem'     => $mensagem,
            'evento'       => 'equipamento_pronto',
        ];

        try {
            return $this->queue()->enqueue('notificar_cliente', $payload);
        } catch (\Throwable $e) {
            error_log('[NotificationService] falha ao enfileirar (pronto): ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Monta a mensagem WhatsApp de "equipamento pronto" (texto fornecido pela
     * loja). Usa *negrito* e quebras \n — a Evolution API renderiza no celular.
     */
    private function montarMensagemPronto(
        string $nome,
        string $equipamentoNome,
        ?float $valorTotal,
    ): string {
        $primeiroNome = trim(strtok($nome ?: '', ' ') ?: '');
        $saudacaoNome = $primeiroNome !== '' ? "*{$primeiroNome}*" : 'tudo bem';

        $equip = trim($equipamentoNome) !== '' ? trim($equipamentoNome) : 'seu equipamento';

        $linhaValor = ($valorTotal !== null && $valorTotal > 0)
            ? '💰 Valor do serviço: R$ ' . number_format($valorTotal, 2, ',', '.')
            : '💰 Valor do serviço: *Sem custo (garantia)*';

        $partes = [
            "👋 Olá, {$saudacaoNome}!",
            '',
            'Anúncio de ótimas notícias! O nosso técnico acabou de finalizar e testar o seguinte equipamento:',
            '',
            "🛠️ *{$equip}*",
            '',
            $linhaValor,
            '',
            '📌 *Aviso importante:*',
            '',
            'Se você tem outros equipamentos aprovados em nossa oficina, nossa equipe técnica continua trabalhando neles. Você receberá uma nova notificação assim que cada um for concluído!',
            '',
            'Se este era o único ou o último da sua lista, ele já está disponível para retirada em nossa loja. Se preferir, pode aguardar os demais para retirar tudo junto. 👍',
            '',
            '🕒 *Nosso Horário de Funcionamento:*',
            '',
            '• Segunda a Sexta: das 8h às 17h45',
            '• Sábado: das 8h às 11h30',
        ];

        return implode("\n", $partes);
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


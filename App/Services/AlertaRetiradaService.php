<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

/**
 * Serviço de alertas de retirada e abandono de equipamentos.
 *
 * Opera por EQUIPAMENTO (os_equipamento), não por OS inteira.
 * Monitora equipamentos com status 'pronto' ou 'cancelado' que ainda
 * aguardam destino físico (retirada, devolução ou descarte).
 *
 * Estados elegíveis para alerta:
 *   - pronto   → aguardando retirada após conserto
 *   - cancelado → aguardando destino físico (devolução ou descarte)
 *
 * Estados excluídos (destino já definido):
 *   - retirado, devolvido, descartado
 *
 * Estados excluídos (ainda em processo técnico):
 *   - aberta, andamento, montagem
 */
final class AlertaRetiradaService
{
    /** Prazo de retirada para equipamentos cancelados (dias) */
    private const PRAZO_CANCELADO = 7;

    /** Prazo de retirada para equipamentos prontos (dias) */
    private const PRAZO_PRONTO = 30;

    /** Prazo legal de abandono (dias) */
    private const PRAZO_ABANDONO = 90;

    /** Intervalo de renotificação (dias) */
    private const INTERVALO_RENOTIFICACAO = 15;

    public function __construct(
        private readonly ?PDO $pdo = null,
        private readonly UploadService $upload = new UploadService(),
    ) {}

    private function pdo(): PDO
    {
        return $this->pdo ?? Database::pdo();
    }

    /**
     * Lista equipamentos pendentes de retirada/destino físico.
     *
     * Retorna um registro por equipamento (não por OS).
     * Elegíveis: status_equip IN ('pronto', 'cancelado').
     * Excluídos: retirado, devolvido, descartado (já finalizados).
     */
    public function listarPendentes(array $filtros = []): array
    {
        $filtroNivel       = trim((string) ($filtros['nivel'] ?? ''));
        $filtroStatus      = trim((string) ($filtros['status'] ?? ''));
        $filtroBusca       = mb_strtolower(trim((string) ($filtros['q'] ?? '')));
        $somenteRenotificar = !empty($filtros['renotificar']);

        $sql = "SELECT
                    eq.id            AS equip_db_id,
                    eq.os_id,
                    eq.ordem_idx     AS equip_idx,
                    eq.ordem_idx + 1 AS equip_num,
                    eq.nome          AS equip_nome,
                    eq.status_equip,
                    eq.status_equip_em,
                    o.nome_cliente,
                    o.telefone,
                    o.doc_cliente,
                    o.cliente_id,
                    COALESCE(eq.status_equip_em, o.data_conclusao, o.updated_at) AS data_base,
                    DATEDIFF(NOW(), COALESCE(eq.status_equip_em, o.data_conclusao, o.updated_at)) AS dias_aguardando,
                    (SELECT COUNT(*) FROM notificacoes_retirada nr WHERE nr.os_id = o.id) AS total_notificacoes,
                    (SELECT MAX(nr.enviado_em) FROM notificacoes_retirada nr WHERE nr.os_id = o.id) AS ultima_notificacao
                FROM os_equipamento eq
                JOIN ordem_servico o ON o.id = eq.os_id
                WHERE eq.status_equip IN ('pronto', 'cancelado')
                  AND o.status NOT IN ('retirado', 'descartado')
                ORDER BY dias_aguardando DESC";

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $dias     = (int) $row['dias_aguardando'];
            $statusEq = $row['status_equip'];

            $prazoRetirada = $statusEq === 'cancelado' ? self::PRAZO_CANCELADO : self::PRAZO_PRONTO;

            if ($dias >= self::PRAZO_ABANDONO) {
                $row['nivel']       = 'abandono';
                $row['nivel_label'] = 'Abandono Legal';
                $row['nivel_cor']   = '#dc2626';
            } elseif ($dias >= $prazoRetirada) {
                $row['nivel']       = 'armazenamento';
                $row['nivel_label'] = 'Taxa de Armazenamento';
                $row['nivel_cor']   = '#f59e0b';
            } else {
                $row['nivel']       = 'normal';
                $row['nivel_label'] = 'Dentro do Prazo';
                $row['nivel_cor']   = '#16a34a';
            }

            $row['dias_excedidos']     = max(0, $dias - $prazoRetirada);
            $row['prazo_retirada']     = $prazoRetirada;
            $row['taxa_armazenamento'] = max(0, $dias - $prazoRetirada) * 2.0;

            $ultimaNotif = $row['ultima_notificacao'];
            if ($ultimaNotif === null) {
                $row['renotificar']            = true;
                $row['dias_desde_notificacao'] = null;
            } else {
                $diasDesdeNotif                = (int) (new \DateTime())->diff(new \DateTime($ultimaNotif))->days;
                $row['renotificar']            = $diasDesdeNotif >= self::INTERVALO_RENOTIFICACAO;
                $row['dias_desde_notificacao'] = $diasDesdeNotif;
            }

            if ($filtroNivel !== '' && $row['nivel'] !== $filtroNivel) {
                continue;
            }

            if ($filtroStatus !== '' && $row['status_equip'] !== $filtroStatus) {
                continue;
            }

            if ($somenteRenotificar && !$row['renotificar']) {
                continue;
            }

            if ($filtroBusca !== '') {
                $haystack = mb_strtolower(implode(' ', [
                    (string) ($row['os_id'] ?? ''),
                    (string) ($row['nome_cliente'] ?? ''),
                    (string) ($row['telefone'] ?? ''),
                    (string) ($row['doc_cliente'] ?? ''),
                    (string) ($row['equip_nome'] ?? ''),
                ]));

                if (!str_contains($haystack, $filtroBusca)) {
                    continue;
                }
            }

            $result[] = $row;
        }

        return $result;
    }

    /**
     * Conta alertas por nível para badges no sidebar/dashboard.
     *
     * @return array{total:int, normal:int, armazenamento:int, abandono:int, renotificar:int}
     */
    public function contarAlertas(): array
    {
        $todos = $this->listarPendentes();
        $contadores = [
            'total'         => count($todos),
            'normal'        => 0,
            'armazenamento' => 0,
            'abandono'      => 0,
            'renotificar'   => 0,
        ];

        foreach ($todos as $item) {
            $contadores[$item['nivel']]++;
            if ($item['renotificar']) {
                $contadores['renotificar']++;
            }
        }

        return $contadores;
    }

    /**
     * Registra uma notificação de retirada no log (por OS).
     */
    public function registrarNotificacao(
        string $osId,
        string $tipo,
        string $mensagem,
        ?int $usuarioId = null,
        ?string $obs = null,
    ): int {
        $tiposValidos = ['whatsapp', 'email', 'ligacao', 'sistema'];
        if (!in_array($tipo, $tiposValidos, true)) {
            $tipo = 'sistema';
        }

        $stmt = $this->pdo()->prepare(
            "INSERT INTO notificacoes_retirada (os_id, tipo, mensagem, enviado_por, obs, enviado_em)
             VALUES (:os_id, :tipo, :mensagem, :enviado_por, :obs, NOW())"
        );
        $stmt->execute([
            ':os_id'       => $osId,
            ':tipo'        => $tipo,
            ':mensagem'    => $mensagem,
            ':enviado_por' => $usuarioId,
            ':obs'         => $obs,
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Anexa um comprovante (print de tela) a uma notificação existente.
     */
    public function anexarComprovante(int $notificacaoId, array $uploadFile): string
    {
        $url = $this->upload->salvarGenerico('comprovantes', $uploadFile);

        $stmt = $this->pdo()->prepare(
            "UPDATE notificacoes_retirada SET print_path = :path WHERE id = :id LIMIT 1"
        );
        $stmt->execute([':path' => $url, ':id' => $notificacaoId]);

        return $url;
    }

    /**
     * Lista o histórico de notificações de uma OS.
     */
    public function historicoNotificacoes(string $osId): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT nr.*, u.nome AS usuario_nome
             FROM notificacoes_retirada nr
             LEFT JOIN usuarios u ON u.id = nr.enviado_por
             WHERE nr.os_id = :os_id
             ORDER BY nr.enviado_em DESC"
        );
        $stmt->execute([':os_id' => $osId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Marca um equipamento específico como descartado por abandono legal (90 dias sem retirada).
     *
     * Motivo jurídico: Art. 1.275, III do Código Civil — bem abandonado.
     * O Termo de Responsabilidade assinado na abertura da OS autoriza este descarte implicitamente.
     *
     * Campos preenchidos:
     *   - status_equip            = 'descartado'
     *   - descarte_autorizado_em  = NOW()   (Termo de abertura autoriza implicitamente)
     *   - descarte_autorizado_por = texto fixo indicando abandono legal
     *   - descarte_autorizado_uid = usuarioId (operador que registra)
     *   - descarte_meio           = NULL     (não aplicável ao fluxo de abandono)
     *   - descarte_executado_em   = NOW()
     *   - descarte_executado_uid  = usuarioId
     *
     * RISCO DOCUMENTADO: Não existe campo `descarte_motivo` para distinguir abandono de
     * descarte autorizado manualmente (9C-3/9C-4). O campo descarte_autorizado_por usa
     * texto livre como diferenciador. Etapa futura pode adicionar descarte_motivo ENUM.
     *
     * @throws \DomainException se equipamento não elegível
     */
    public function marcarDescarteEquipamentoPorAbandono(string $osId, int $equipIdx, int $usuarioId): array
    {
        $osEncerrada   = false;
        $osStatusFinal = null;

        $this->pdo()->beginTransaction();
        try {
            // Lock OS
            $stOs = $this->pdo()->prepare(
                "SELECT status FROM ordem_servico WHERE id = ? LIMIT 1 FOR UPDATE"
            );
            $stOs->execute([$osId]);
            $osRow = $stOs->fetch(PDO::FETCH_ASSOC);
            if (!$osRow) {
                throw new \DomainException("OS #{$osId} não encontrada.");
            }

            // Lock equipamento
            $stEq = $this->pdo()->prepare(
                "SELECT status_equip FROM os_equipamento
                  WHERE os_id = ? AND ordem_idx = ? LIMIT 1 FOR UPDATE"
            );
            $stEq->execute([$osId, $equipIdx]);
            $eqRow = $stEq->fetch(PDO::FETCH_ASSOC);
            if (!$eqRow) {
                throw new \DomainException("Equipamento #{$equipIdx} não encontrado na OS #{$osId}.");
            }

            $statusEq = $eqRow['status_equip'];

            if ($statusEq === 'descartado') {
                throw new \DomainException("Equipamento já foi descartado.");
            }
            if (in_array($statusEq, ['retirado', 'devolvido'], true)) {
                throw new \DomainException(
                    "Equipamento já recebeu destino físico ({$statusEq}). Descarte por abandono não aplicável."
                );
            }
            if (!in_array($statusEq, ['pronto', 'cancelado'], true)) {
                throw new \DomainException(
                    "Equipamento com status '{$statusEq}' não elegível para descarte por abandono. " .
                    "Apenas equipamentos 'pronto' ou 'cancelado' (aguardando destino) são elegíveis."
                );
            }

            // Registra descarte por abandono — preenche autorização e execução simultaneamente
            $this->pdo()->prepare(
                "UPDATE os_equipamento
                    SET status_equip            = 'descartado',
                        status_equip_em         = NOW(),
                        descarte_autorizado_em  = NOW(),
                        descarte_autorizado_por = 'Abandono legal — Art. 1.275, III CC (Termo de abertura da OS)',
                        descarte_autorizado_uid = ?,
                        descarte_meio           = NULL,
                        descarte_executado_em   = NOW(),
                        descarte_executado_uid  = ?
                  WHERE os_id = ? AND ordem_idx = ? LIMIT 1"
            )->execute([$usuarioId, $usuarioId, $osId, $equipIdx]);

            // Verificar se todos os equipamentos estão em estado terminal físico
            // 'cancelado' NÃO conta — ainda aguarda destino físico.
            $stPend = $this->pdo()->prepare(
                "SELECT COUNT(*) FROM os_equipamento
                  WHERE os_id = ? AND status_equip NOT IN ('retirado','devolvido','descartado')"
            );
            $stPend->execute([$osId]);
            $pendentes = (int) $stPend->fetchColumn();

            if ($pendentes === 0) {
                $osEncerrada = true;

                // Regra de closure:
                // 1. Algum retirado → OS = 'retirado'
                // 2. Nenhum retirado, algum devolvido → OS = 'cancelado' (mix sem serviço prestado)
                // 3. Nenhum retirado, nenhum devolvido (todos descartados) → OS = 'descartado'
                $stTemRetirado = $this->pdo()->prepare(
                    "SELECT COUNT(*) FROM os_equipamento WHERE os_id = ? AND status_equip = 'retirado'"
                );
                $stTemRetirado->execute([$osId]);
                $temRetirado = (int) $stTemRetirado->fetchColumn() > 0;

                if ($temRetirado) {
                    $osStatusFinal = 'retirado';
                } else {
                    $stTemDevolvido = $this->pdo()->prepare(
                        "SELECT COUNT(*) FROM os_equipamento WHERE os_id = ? AND status_equip = 'devolvido'"
                    );
                    $stTemDevolvido->execute([$osId]);
                    $temDevolvido  = (int) $stTemDevolvido->fetchColumn() > 0;
                    $osStatusFinal = $temDevolvido ? 'cancelado' : 'descartado';
                }

                $this->pdo()->prepare(
                    "UPDATE ordem_servico SET status = ? WHERE id = ? LIMIT 1"
                )->execute([$osStatusFinal, $osId]);
            }

            $this->pdo()->commit();

        } catch (Throwable $e) {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }
            throw $e;
        }

        // Notificação fora da transação — melhor-esforço
        try {
            $equipNumVisual = $equipIdx + 1;
            $this->registrarNotificacao(
                $osId,
                'sistema',
                "Equipamento #{$equipNumVisual} marcado como DESCARTADO por abandono legal (Art. 1.275, III CC). " .
                "Prazo de " . self::PRAZO_ABANDONO . " dias ultrapassado sem retirada pelo cliente.",
                $usuarioId,
                'Descarte por abandono registrado pelo operador do sistema.'
            );
        } catch (Throwable) {
            // falha na notificação não deve reverter o descarte já confirmado
        }

        return [
            'os_id'           => $osId,
            'equip_idx'       => $equipIdx,
            'os_encerrada'    => $osEncerrada,
            'os_status_final' => $osStatusFinal,
        ];
    }

    /**
     * Busca dados de um equipamento pendente específico.
     */
    public function buscarEquipamentoPendente(string $osId, int $equipIdx): ?array
    {
        foreach ($this->listarPendentes() as $item) {
            if ($item['os_id'] === $osId && (int) $item['equip_idx'] === $equipIdx) {
                return $item;
            }
        }
        return null;
    }

    // ════════════════════════════════════════════════════════════════════
    //  AVISOS DE RETIRADA EM LOTE
    // ════════════════════════════════════════════════════════════════════

    private const AVISO_INTERVALO_DIAS = 2;
    private const AVISO_COOLDOWN_DIAS = 7;
    private const AVISO_LOTE_MAX = 20;

    /**
     * Lista equipamentos elegíveis para aviso de retirada, agrupados por telefone.
     *
     * @return array<int, array{telefone:string, tel_digits:string, nome_cliente:string, cliente_id:int|null, equipamentos:array<int,array<string,mixed>>}>
     */
    public function listarElegiveisParaAviso(): array
    {
        $diasMin = self::AVISO_INTERVALO_DIAS;
        $cooldown = self::AVISO_COOLDOWN_DIAS;

        $sql = "
            SELECT
                eq.os_id,
                eq.ordem_idx,
                eq.nome AS equip_nome,
                eq.fabricante,
                eq.modelo,
                eq.voltagem,
                eq.status_equip_em AS pronto_em,
                os.nome_cliente,
                COALESCE(NULLIF(os.contato_telefone, ''), NULLIF(os.telefone, '')) AS telefone,
                os.contato_nome,
                os.cliente_id
            FROM os_equipamento eq
            INNER JOIN ordem_servico os ON os.id = eq.os_id
            WHERE eq.status_equip = 'pronto'
              AND os.status NOT IN ('retirado', 'descartado', 'cancelado')
              AND eq.status_equip_em IS NOT NULL
              AND eq.status_equip_em <= NOW() - INTERVAL {$diasMin} DAY
              AND NOT EXISTS (
                  SELECT 1 FROM notificacoes_retirada nr
                   WHERE nr.os_id = eq.os_id
                     AND (nr.equip_idx = eq.ordem_idx OR nr.equip_idx IS NULL)
                     AND nr.tipo = 'whatsapp'
                     AND nr.status_envio = 'enviado'
                     AND nr.enviado_em >= NOW() - INTERVAL {$cooldown} DAY
              )
              AND COALESCE(NULLIF(os.contato_telefone, ''), NULLIF(os.telefone, '')) IS NOT NULL
            ORDER BY os.nome_cliente ASC, eq.os_id ASC, eq.ordem_idx ASC";

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $porTelefone = [];
        foreach ($rows as $row) {
            $telDigits = preg_replace('/\D/', '', (string) ($row['telefone'] ?? '')) ?? '';
            if ($telDigits === '') {
                continue;
            }

            if (!isset($porTelefone[$telDigits])) {
                $porTelefone[$telDigits] = [
                    'telefone'     => (string) ($row['telefone'] ?? ''),
                    'tel_digits'   => $telDigits,
                    'nome_cliente' => (string) ($row['nome_cliente'] ?? ''),
                    'cliente_id'   => $row['cliente_id'] !== null ? (int) $row['cliente_id'] : null,
                    'equipamentos' => [],
                ];
            }

            $porTelefone[$telDigits]['equipamentos'][] = [
                'os_id'      => (string) $row['os_id'],
                'ordem_idx'  => (int) $row['ordem_idx'],
                'equip_nome' => (string) ($row['equip_nome'] ?? ''),
                'fabricante' => (string) ($row['fabricante'] ?? ''),
                'modelo'     => (string) ($row['modelo'] ?? ''),
                'voltagem'   => (string) ($row['voltagem'] ?? ''),
                'pronto_em'  => $row['pronto_em'],
            ];
        }

        return array_values($porTelefone);
    }

    /**
     * Monta a mensagem de aviso para um cliente.
     */
    public function montarMensagemAviso(string $nomeCliente, array $equipamentos): string
    {
        $tz = new \DateTimeZone('America/Sao_Paulo');
        $hora = (int) (new \DateTime('now', $tz))->format('H');
        $saudacao = match (true) {
            $hora < 12 => 'Bom dia',
            $hora < 18 => 'Boa tarde',
            default => 'Boa noite',
        };

        $partes = preg_split('/\s+/u', mb_strtolower(trim($nomeCliente), 'UTF-8')) ?: [];
        $primeiro = mb_convert_case((string) ($partes[0] ?? $nomeCliente), MB_CASE_TITLE, 'UTF-8');
        if ($primeiro === '') {
            $primeiro = 'tudo bem';
        }

        $cabecalho = "*{$saudacao}, {$primeiro}, tudo bem?*\n"
            . "Carlos aqui, da Multimáquinas.\n\n";

        $rodape = "\n\nQuando puder, pedimos por gentileza que combine a retirada conosco.\n"
            . "Pode responder por aqui mesmo para confirmar o melhor horário.\n\n"
            . "Obrigado.";

        if (count($equipamentos) === 1) {
            $eq = $equipamentos[0];
            $desc = $this->descricaoEquipamento($eq);
            return $cabecalho
                . "Passando para lembrar que o equipamento abaixo já está pronto para retirada:\n\n"
                . "*OS:* #{$eq['os_id']}\n"
                . "*Equipamento:* {$desc}"
                . $rodape;
        }

        $lista = '';
        foreach ($equipamentos as $eq) {
            $lista .= '• OS #' . $eq['os_id'] . ' — ' . $this->descricaoEquipamento($eq) . "\n";
        }

        return $cabecalho
            . "Passando para lembrar que os equipamentos abaixo já estão prontos para retirada:\n\n"
            . rtrim($lista)
            . $rodape;
    }

    private function descricaoEquipamento(array $eq): string
    {
        $nome = mb_strtoupper(trim((string) ($eq['equip_nome'] ?? '')), 'UTF-8');
        $extras = [];
        foreach (['fabricante', 'modelo', 'voltagem'] as $campo) {
            $valor = trim((string) ($eq[$campo] ?? ''));
            if ($valor !== '') {
                $extras[] = $valor;
            }
        }

        return $nome . ($extras !== [] ? ' ' . implode(' ', $extras) : '');
    }

    /**
     * Envia avisos em lote. Recebe array de clientes já validados pelo front.
     *
     * @param array<int, array{telefone:string, nome_cliente:string, equipamentos:array<int,array<string,mixed>>}> $selecionados
     * @return array{enviados:int, falhas:int, sem_telefone:int, total:int, detalhes:array<int,array<string,mixed>>}
     */
    public function enviarAvisoLote(array $selecionados, int $usuarioId): array
    {
        if (count($selecionados) > self::AVISO_LOTE_MAX) {
            $selecionados = array_slice($selecionados, 0, self::AVISO_LOTE_MAX);
        }

        $whatsapp = new WhatsappService($this->pdo());
        $loteId = bin2hex(random_bytes(8));

        $resultado = [
            'enviados' => 0,
            'falhas' => 0,
            'sem_telefone' => 0,
            'total' => count($selecionados),
            'detalhes' => [],
        ];

        foreach ($selecionados as $cliente) {
            $telefone = trim((string) ($cliente['telefone'] ?? ''));
            $equipamentos = is_array($cliente['equipamentos'] ?? null) ? $cliente['equipamentos'] : [];

            if ($telefone === '' || $equipamentos === []) {
                $resultado['sem_telefone']++;
                continue;
            }

            $mensagem = $this->montarMensagemAviso((string) ($cliente['nome_cliente'] ?? ''), $equipamentos);

            // enviarTextoComRetorno nunca lança; ainda assim blindamos para que a falha
            // de um cliente jamais aborte o lote inteiro nem derrube a tela (HTTP 500).
            try {
                $envio = $whatsapp->enviarTextoComRetorno($telefone, $mensagem);
            } catch (Throwable $e) {
                $envio = ['ok' => false, 'response' => null, 'erro' => $e->getMessage()];
            }

            $ok         = (bool) ($envio['ok'] ?? false);
            $status     = $ok ? 'enviado' : 'falha';
            $retornoApi = $envio['response'] ?? ($envio['erro'] ?? null);

            foreach ($equipamentos as $eq) {
                try {
                    $this->registrarAvisoEquipamento(
                        osId: (string) $eq['os_id'],
                        equipIdx: (int) $eq['ordem_idx'],
                        tipo: 'whatsapp',
                        mensagem: $mensagem,
                        usuarioId: $usuarioId,
                        statusEnvio: $status,
                        loteId: $loteId,
                        retornoApi: $retornoApi,
                    );
                } catch (Throwable) {
                    // Registro de histórico não deve quebrar o lote.
                }
            }

            $ok ? $resultado['enviados']++ : $resultado['falhas']++;
            $resultado['detalhes'][] = [
                'nome' => (string) ($cliente['nome_cliente'] ?? ''),
                'telefone' => $telefone,
                'status' => $status,
                'equipamentos' => count($equipamentos),
            ];
        }

        return $resultado;
    }

    /**
     * Registra uma entrada por equipamento em notificacoes_retirada.
     */
    public function registrarAvisoEquipamento(
        string $osId,
        int $equipIdx,
        string $tipo,
        string $mensagem,
        int $usuarioId,
        string $statusEnvio = 'enviado',
        ?string $loteId = null,
        mixed $retornoApi = null,
        ?string $motivoIgnorado = null,
    ): void {
        $this->pdo()->prepare(
            "INSERT INTO notificacoes_retirada
                (os_id, equip_idx, tipo, mensagem, enviado_por, status_envio, lote_id, retorno_api, motivo_ignorado)
             VALUES
                (:os, :idx, :tipo, :msg, :uid, :status, :lote, :ret, :motivo)"
        )->execute([
            ':os' => $osId,
            ':idx' => $equipIdx,
            ':tipo' => $tipo,
            ':msg' => $mensagem,
            ':uid' => $usuarioId,
            ':status' => $statusEnvio,
            ':lote' => $loteId,
            ':ret' => $retornoApi !== null ? json_encode($retornoApi, JSON_UNESCAPED_UNICODE) : null,
            ':motivo' => $motivoIgnorado,
        ]);
    }
}

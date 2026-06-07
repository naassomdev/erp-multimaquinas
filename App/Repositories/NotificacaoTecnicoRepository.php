<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Helpers\ClienteHelper;
use PDO;

final class NotificacaoTecnicoRepository
{
    private const TIPOS_VALIDOS = ['aprovado', 'cancelado', 'pronto', 'info', 'descarte', 'diagnostico'];
    private const DESTINOS_VALIDOS = ['oficina', 'recepcao'];

    public function listarNaoLidas(int $limit = 50, string $destino = 'oficina'): array
    {
        $destino = $this->normalizarDestino($destino);
        $sql = "SELECT n.id, n.os_id, n.equip_idx, n.tipo, n.destino, n.mensagem, n.lida, n.created_at,
                       os.nome_cliente,
                       c.nome AS cliente_nome_cadastro,
                       c.nome_fantasia AS cliente_nome_fantasia,
                       e.nome AS equip_nome,
                       e.defeito AS equip_defeito
                  FROM notificacoes_tecnico n
             LEFT JOIN ordem_servico os ON os.id = n.os_id
             LEFT JOIN clientes c ON c.id = os.cliente_id
             LEFT JOIN os_equipamento e ON e.os_id = n.os_id AND e.ordem_idx = n.equip_idx
                 WHERE n.lida = 0 AND n.destino = :destino
                 ORDER BY n.created_at DESC
                 LIMIT :lim";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->bindValue(':destino', $destino);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map([$this, 'formatarParaListagem'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function contarNaoLidas(string $destino = 'oficina'): int
    {
        $destino = $this->normalizarDestino($destino);
        $sql = "SELECT COUNT(*) FROM notificacoes_tecnico WHERE lida = 0 AND destino = :destino";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':destino' => $destino]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    public function criar(string $osId, int $equipIdx, string $tipo, string $mensagem, string $destino = 'oficina'): int
    {
        if (!in_array($tipo, self::TIPOS_VALIDOS, true)) {
            $tipo = 'info';
        }
        $destino = $this->normalizarDestino($destino);
        $sql = "INSERT INTO notificacoes_tecnico (os_id, equip_idx, tipo, destino, mensagem)
                VALUES (:os, :idx, :tipo, :destino, :msg)";
        $pdo = Database::pdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':os'      => $osId,
            ':idx'     => $equipIdx,
            ':tipo'    => $tipo,
            ':destino' => $destino,
            ':msg'     => $mensagem,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function marcarLida(int $id, string $destino = 'oficina'): void
    {
        $destino = $this->normalizarDestino($destino);
        $sql = "UPDATE notificacoes_tecnico SET lida = 1 WHERE id = :id AND destino = :destino LIMIT 1";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':id' => $id, ':destino' => $destino]);
    }

    public function marcarTodasLidasPorOs(string $osId, string $destino = 'oficina'): void
    {
        $destino = $this->normalizarDestino($destino);
        $sql = "UPDATE notificacoes_tecnico SET lida = 1 WHERE os_id = :os AND destino = :destino";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':os' => $osId, ':destino' => $destino]);
    }

    /**
     * Marca todas as notificações de técnicos pendentes como lidas.
     */
    public function marcarTodasComoLidas(string $destino = 'oficina'): void
    {
        $destino = $this->normalizarDestino($destino);
        $sql = "UPDATE notificacoes_tecnico SET lida = 1 WHERE lida = 0 AND destino = :destino";
        Database::pdo()->prepare($sql)->execute([':destino' => $destino]);
    }

    /**
     * Marca como lidas as notificações não lidas de um tipo específico
     * para um equipamento. Usado para descartar notificações obsoletas
     * quando o contexto muda (ex: descarte autorizado invalida 'cancelado').
     */
    public function marcarLidasPorOsEquipTipo(string $osId, int $equipIdx, string $tipo, string $destino = 'oficina'): void
    {
        $destino = $this->normalizarDestino($destino);
        $sql = "UPDATE notificacoes_tecnico
                   SET lida = 1
                 WHERE os_id = :os AND equip_idx = :idx AND tipo = :tipo AND destino = :destino AND lida = 0";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':os' => $osId, ':idx' => $equipIdx, ':tipo' => $tipo, ':destino' => $destino]);
    }

    /**
     * @param array<int, string> $tipos
     */
    public function marcarLidasPorOsEquipTipos(string $osId, int $equipIdx, array $tipos, string $destino = 'oficina'): void
    {
        $tipos = array_values(array_filter($tipos, static fn($tipo) => in_array($tipo, self::TIPOS_VALIDOS, true)));
        if (empty($tipos)) {
            return;
        }

        $destino = $this->normalizarDestino($destino);
        $placeholders = implode(',', array_fill(0, count($tipos), '?'));
        $sql = "UPDATE notificacoes_tecnico
                   SET lida = 1
                 WHERE os_id = ?
                   AND equip_idx = ?
                   AND destino = ?
                   AND lida = 0
                   AND tipo IN ({$placeholders})";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute(array_merge([$osId, $equipIdx, $destino], $tipos));
    }

    private function normalizarDestino(string $destino): string
    {
        return in_array($destino, self::DESTINOS_VALIDOS, true) ? $destino : 'oficina';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatarParaListagem(array $row): array
    {
        $nomeCadastro = trim((string) ($row['cliente_nome_cadastro'] ?? ''));
        $nomeOs = trim((string) ($row['nome_cliente'] ?? ''));
        $cliente = ClienteHelper::nomeParaMensagem([
            'nome_fantasia' => $row['cliente_nome_fantasia'] ?? '',
            'nome'          => $nomeCadastro !== '' ? $nomeCadastro : $nomeOs,
        ]);

        $row['cliente_nome'] = $cliente;
        $row['equip_nome'] = trim((string) ($row['equip_nome'] ?? ''));
        $row['equip_tipo_label'] = $this->inferirTipoEquipamento(
            (string) ($row['equip_nome'] ?? ''),
            (string) ($row['equip_defeito'] ?? '')
        );
        $row['url'] = $this->urlDestino($row);
        $row['url_title'] = $this->tituloUrlDestino($row);

        unset(
            $row['cliente_nome_cadastro'],
            $row['cliente_nome_fantasia'],
            $row['nome_cliente'],
            $row['equip_defeito']
        );

        return $row;
    }

    /**
     * Notificações comerciais/orçamentárias abrem o orçamento do equipamento
     * mesmo para oficina. A tela técnica fica só para ações de bancada.
     *
     * @param array<string, mixed> $row
     */
    private function urlDestino(array $row): string
    {
        $osId = rawurlencode((string) ($row['os_id'] ?? ''));
        $equipIdx = max(0, (int) ($row['equip_idx'] ?? 0));
        $destino = (string) ($row['destino'] ?? 'oficina');

        if ($destino === 'recepcao') {
            return "/orcamento/{$osId}#equip-{$equipIdx}";
        }

        return "/tecnico/os/{$osId}/equipamento/{$equipIdx}";
    }

    /**
     * @param array<string, mixed> $row
     */
    private function tituloUrlDestino(array $row): string
    {
        return (string) ($row['destino'] ?? 'oficina') === 'recepcao'
            ? 'Abrir orçamento deste equipamento'
            : 'Abrir painel técnico deste equipamento';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isNotificacaoComercial(array $row): bool
    {
        $tipo = $this->normalizarTextoBusca((string) ($row['tipo'] ?? ''));
        if (in_array($tipo, ['APROVADO', 'CANCELADO', 'DIAGNOSTICO'], true)) {
            return true;
        }

        $mensagem = $this->normalizarTextoBusca((string) ($row['mensagem'] ?? ''));

        if ($tipo === 'DESCARTE') {
            return false;
        }

        foreach ([
            'SERVICO TERCEIRIZADO RETORNOU',
            'PECAS FORNECIDAS PELO CLIENTE',
            'PECAS RECEBIDAS',
            'LIBERADO PARA MONTAGEM',
            'INICIAR MONTAGEM',
            'INICIAR MONTAGEM/CONSERTO',
            'REALIZAR DESCARTE',
            'AUTORIZOU DESCARTE',
        ] as $marcadorTecnico) {
            if (str_contains($mensagem, $marcadorTecnico)) {
                return false;
            }
        }

        foreach ([
            'ORCAMENTO',
            'APROVACAO',
            'CANCELAMENTO',
            'CLIENTE RECUSOU',
            'AGUARDANDO PECAS',
            'NECESSIDADE DE COMPRA',
            'COMPRA',
            'PECAS PENDENTES',
            'REVISAR ORCAMENTO',
        ] as $marcadorComercial) {
            if (str_contains($mensagem, $marcadorComercial)) {
                return true;
            }
        }

        return false;
    }

    private function inferirTipoEquipamento(string $nome, string $defeito): string
    {
        $texto = $this->normalizarTextoBusca($nome . ' ' . $defeito);
        if (preg_match('/\b(MOTOBOMBA|MOTO BOMBA|BOMBA SUBMERSA|MOTOR ELETRICO|MOTOR)\b/u', $texto)) {
            return 'Motor';
        }

        return 'Equipamento';
    }

    private function normalizarTextoBusca(string $texto): string
    {
        $texto = trim(preg_replace('/\s+/', ' ', $texto) ?? $texto);
        $texto = strtr($texto, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c',
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'Ç' => 'C',
        ]);

        return function_exists('mb_strtoupper')
            ? mb_strtoupper($texto, 'UTF-8')
            : strtoupper($texto);
    }
}

<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\NfseRepository;
use DomainException;
use PDO;

final class NfseDraftService
{
    public const MSG_TOTAL_BLOQUEADO = 'A configuração fiscal não permite emitir NFS-e pelo valor total consolidado da OS. Revise a composição fiscal do serviço.';

    public function __construct(
        private readonly NfseSettingsService $settings = new NfseSettingsService(),
        private readonly NfseDescricaoService $descricao = new NfseDescricaoService(),
        private readonly NfseRepository $repo = new NfseRepository(),
        private readonly ?PDO $pdo = null,
    ) {}

    private function pdo(): PDO
    {
        return $this->pdo ?? Database::pdo();
    }

    /**
     * @return array<string,mixed>
     */
    public function preparar(string $osId, ?int $orcamentoId = null): array
    {
        $sql = "SELECT
                    os.id AS os_id,
                    os.cliente_id,
                    os.nome_cliente AS os_nome_cliente,
                    os.doc_cliente,
                    c.nome AS cliente_nome,
                    c.cpf_cnpj,
                    c.endereco,
                    c.numero,
                    c.complemento,
                    c.bairro,
                    c.cod_cidade,
                    c.cidade,
                    c.uf,
                    c.cep,
                    o.id AS orcamento_id,
                    o.equip_idx,
                    o.status AS orcamento_status,
                    o.total AS valor_total,
                    o.obs_tecnico,
                    o.obs_admin,
                    eq.nome AS equip_nome,
                    eq.fabricante,
                    eq.modelo,
                    eq.serie
                FROM ordem_servico os
                LEFT JOIN clientes c ON c.id = os.cliente_id
                LEFT JOIN orcamentos o ON o.os_id = os.id
                LEFT JOIN os_equipamento eq ON eq.os_id = os.id AND eq.ordem_idx = o.equip_idx
                WHERE os.id = ?
                  AND o.status = 'aprovado'
                  AND (? IS NULL OR o.id = ?)
                ORDER BY o.status = 'aprovado' DESC, o.id DESC
                LIMIT 1";
        $st = $this->pdo()->prepare($sql);
        $st->execute([$osId, $orcamentoId, $orcamentoId]);
        $dados = $st->fetch(PDO::FETCH_ASSOC);
        if (!$dados || empty($dados['orcamento_id'])) {
            throw new DomainException('OS/orçamento não localizado para gerar rascunho de NFS-e.');
        }

        $dados['descricao_servico'] = $this->descricao->consolidada($dados);
        $dados['settings'] = $this->settings->obter();
        $dados['validacoes'] = $this->validarDados($dados, false);

        return $dados;
    }

    public function criar(string $osId, ?int $orcamentoId, int $usuarioId, ?string $descricaoEditada = null): int
    {
        $dados = $this->preparar($osId, $orcamentoId);
        $settings = $this->settings->obter();

        if (($settings['contador_aprova_total_os'] ?? '0') !== '1') {
            throw new DomainException(self::MSG_TOTAL_BLOQUEADO);
        }

        $erros = $this->validarDados($dados, true);
        if ($erros !== []) {
            throw new DomainException(implode(' ', $erros));
        }

        $descricao = trim((string)($descricaoEditada ?? ''));
        if ($descricao === '') {
            $descricao = (string)$dados['descricao_servico'];
        }

        return $this->repo->criarRascunho([
            'os_id' => (string)$dados['os_id'],
            'orcamento_id' => (int)$dados['orcamento_id'],
            'cliente_id' => isset($dados['cliente_id']) ? (int)$dados['cliente_id'] : null,
            'ambiente' => (string)($settings['ambiente'] ?? 'homologacao'),
            'valor_total' => (float)$dados['valor_total'],
            'descricao_servico' => $descricao,
            'serie_dps' => (string)($settings['serie_dps'] ?? '1'),
            'competencia' => date('Y-m-d'),
            'created_by' => $usuarioId,
            'updated_by' => $usuarioId,
        ]);
    }

    /**
     * @param array<string,mixed> $dados
     * @return list<string>
     */
    public function validarDados(array $dados, bool $bloquearTotalConsolidado = true): array
    {
        $settings = $this->settings->obter();
        $erros = [];

        if (trim((string)($dados['cpf_cnpj'] ?? $dados['doc_cliente'] ?? '')) === '') {
            $erros[] = 'Cliente precisa ter CPF/CNPJ.';
        }
        if (trim((string)($dados['cliente_nome'] ?? $dados['os_nome_cliente'] ?? '')) === '') {
            $erros[] = 'Cliente precisa ter nome/razão social.';
        }
        if (trim((string)($dados['endereco'] ?? '')) === '' || trim((string)($dados['numero'] ?? '')) === '') {
            $erros[] = 'Cliente deve ter endereço mínimo preenchido.';
        }
        if (trim((string)($dados['os_id'] ?? '')) === '') {
            $erros[] = 'OS deve existir.';
        }
        if ((float)($dados['valor_total'] ?? 0) <= 0) {
            $erros[] = 'Valor da NFS-e deve ser maior que zero.';
        }
        if (trim((string)($dados['descricao_servico'] ?? '')) === '') {
            $erros[] = 'Descrição do serviço é obrigatória.';
        }
        if (($settings['ambiente'] ?? '') === '') {
            $erros[] = 'Configuração fiscal deve estar preenchida.';
        }
        if ($bloquearTotalConsolidado && ($settings['contador_aprova_total_os'] ?? '0') !== '1') {
            $erros[] = self::MSG_TOTAL_BLOQUEADO;
        }

        return $erros;
    }

    public function salvarConferencia(int $notaId, string $descricao, int $usuarioId): void
    {
        $nota = $this->repo->buscarPorId($notaId);
        if ($nota === null) {
            throw new DomainException("Nota fiscal #{$notaId} não encontrada.");
        }
        if (!in_array((string)$nota['status'], ['rascunho', 'pendente', 'rejeitada', 'erro'], true)) {
            throw new DomainException('Apenas rascunhos ou notas pendentes podem ser editados na conferência.');
        }
        $descricao = trim($descricao);
        if ($descricao === '') {
            throw new DomainException('Descrição do serviço é obrigatória.');
        }

        $this->repo->atualizarConferencia($notaId, $descricao, $usuarioId);
    }
}

<?php
declare(strict_types=1);
namespace App\Jobs;

use App\Services\Fiscal\CertificateManager;
use App\Services\Fiscal\FiscalGuard;
use App\Services\Fiscal\NfseService;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Handler do job 'emitir_nfse'.
 * Executado pelo worker em background — nunca de forma síncrona em requisição HTTP.
 */
final class EmitirNfseJob
{
    private ?CertificateManager $certManager = null;
    private ?NfseService        $nfseService = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function handle(array $payload): void
    {
        $notaId = (int)($payload['nota_id'] ?? 0);
        if ($notaId <= 0) {
            throw new InvalidArgumentException("nota_id inválido no payload: " . json_encode($payload));
        }
        if (!FiscalGuard::canRunWorker($this->pdo)) {
            FiscalGuard::auditBlock($this->pdo, 'erp-nfse_job_emitir_nfse', $notaId, (int)($payload['operador_id'] ?? 0));
            throw new RuntimeException(FiscalGuard::blockMessage($this->pdo, 'erp-nfse_job_emitir_nfse'));
        }

        // ── Busca dados da nota + OS ──────────────────────────────────────────
        $st = $this->pdo->prepare(
            "SELECT nf.id, nf.os_id, nf.lancamento_id, nf.status,
                    nf.valor_total,
                    nf.descricao_servico,
                    lr.valor       AS valor_lancamento,
                    lr.descricao   AS descricao_lancamento,
                    COALESCE(lr.cliente_id, nf.cliente_id, os.cliente_id) AS cliente_id,
                    COALESCE(c.nome, os.nome_cliente) AS nome_cliente,
                    COALESCE(c.cpf_cnpj, os.doc_cliente) AS cpf_cnpj
             FROM notas_fiscais nf
             LEFT JOIN lancamentos_receber lr ON lr.id = nf.lancamento_id
             LEFT JOIN ordem_servico os       ON os.id = nf.os_id
             LEFT JOIN clientes c             ON c.id  = COALESCE(lr.cliente_id, nf.cliente_id, os.cliente_id)
             WHERE nf.id = ?
             LIMIT 1"
        );
        $st->execute([$notaId]);
        $nota = $st->fetch(PDO::FETCH_ASSOC);

        if (!$nota) {
            throw new RuntimeException("Nota fiscal #{$notaId} não encontrada na base.");
        }

        // Idempotência: não reemite nota já autorizada
        if ($nota['status'] === 'autorizada') {
            return;
        }

        // ── Emite via NfseService ─────────────────────────────────────────────
        $certPath = (string)(getenv('CERT_PATH') ?: '');
        $certPass = (string)(getenv('CERT_PASSWORD') ?: '');
        $this->certManager = new CertificateManager($certPath, $certPass);
        $this->nfseService = new NfseService($this->certManager);

        $ambiente  = (string)(getenv('NFSE_AMBIENTE') ?: 'homologacao');
        $valorServico = (float)($nota['valor_total'] ?? 0);
        if ($valorServico <= 0.0) {
            $valorServico = (float)($nota['valor_lancamento'] ?? 0);
        }
        $descricaoServico = trim((string)($nota['descricao_servico'] ?? ''));
        if ($descricaoServico === '') {
            $descricaoServico = trim((string)($nota['descricao_lancamento'] ?? ''));
        }
        if ($descricaoServico === '') {
            $descricaoServico = "Serviço de assistência técnica — OS #{$nota['os_id']}";
        }

        $resultado = $this->nfseService->emitir([
            'nota_id'       => $notaId,
            'os_id'         => (string)$nota['os_id'],
            'nome_cliente'  => (string)$nota['nome_cliente'],
            'cpf_cnpj'      => preg_replace('/\D/', '', (string)($nota['cpf_cnpj'] ?? '')),
            'valor_servico' => $valorServico,
            'descricao'     => $descricaoServico,
            'ambiente'      => $ambiente,
        ]);

        // ── Persiste o resultado ──────────────────────────────────────────────
        $this->pdo->prepare(
            "UPDATE notas_fiscais
             SET status      = ?,
                 numero      = ?,
                 protocolo   = ?,
                 xml_retorno = ?,
                 atualizado_em = NOW()
             WHERE id = ?
             LIMIT 1"
        )->execute([
            $resultado['status'],
            $resultado['numero']    ?? null,
            $resultado['protocolo'] ?? null,
            isset($resultado['xml']) ? (string)$resultado['xml'] : null,
            $notaId,
        ]);

        // Se rejeitada após todas as tentativas, logar para o operador
        if ($resultado['status'] !== 'autorizada') {
            error_log(
                "[EmitirNfseJob] Nota #{$notaId} com status '{$resultado['status']}' — " .
                "verifique a configuração em NfseService.php"
            );
        }
    }
}

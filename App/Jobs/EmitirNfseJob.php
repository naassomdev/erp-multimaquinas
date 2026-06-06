<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Core\Env;
use App\Queue\JobBlockedException;
use App\Services\AuditoriaService;
use App\Services\NfseSettingsService;
use App\Services\Fiscal\CertificateManager;
use App\Services\Fiscal\NfseService;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Handler do job 'emitir_nfse'. Executado pelo worker em background — nunca síncrono no HTTP.
 *
 * Os dados da nota são montados a partir de notas_fiscais + lancamentos_receber + clientes.
 * Não dependemos de ordem_servico aqui porque seu PK é varchar(24) ("AAAAMMDD-NNN") enquanto
 * notas_fiscais.os_id é int — o JOIN direto produz match parcial e dados errados.
 */
final class EmitirNfseJob
{
    private ?CertificateManager $certManager = null;
    private NfseService         $nfseService;

    public function __construct(private readonly PDO $pdo)
    {
        $settings = (new NfseSettingsService())->obter();
        $certPath = trim((string)($settings['cert_path'] ?? Env::get('CERT_PATH', '') ?? ''));
        $certPass = (string)($settings['cert_password'] ?? Env::get('CERT_PASSWORD', '') ?? '');

        if ($certPath !== '' && is_readable($certPath)) {
            try {
                $this->certManager = new CertificateManager($certPath, $certPass);
            } catch (Throwable) {
                // Em modo simulação seguimos mesmo sem certificado válido.
                $this->certManager = null;
            }
        }

        $this->nfseService = new NfseService($this->certManager);
    }

    public function handle(array $payload): void
    {
        $settings = (new NfseSettingsService())->obter();
        $notaId = (int)($payload['nota_id'] ?? 0);
        if ($notaId <= 0) {
            throw new InvalidArgumentException(
                'nota_id inválido no payload: ' . json_encode($payload, JSON_UNESCAPED_UNICODE)
            );
        }
        $settingsService = new NfseSettingsService();
        if (!$settingsService->canRunFiscalWorker($settings)) {
            $motivo = 'Worker fiscal bloqueado por configuração NFS-e.';
            (new AuditoriaService())->registrar('notas_fiscais', (string)$notaId, 'NFSE_BLOQUEIO', [
                'acao_tentada' => 'worker_emitir_nfse',
                'job_id' => (int)($payload['_job_id'] ?? 0),
                'motivo' => $motivo,
                'flags' => $settingsService->fiscalFlagsSnapshot($settings),
            ]);
            error_log('[EmitirNfseJob] ' . $motivo . ' nota_id=' . $notaId);
            throw new JobBlockedException($motivo);
        }

        $st = $this->pdo->prepare(
            "SELECT nf.id, nf.os_id, nf.lancamento_id, nf.status,
                    lr.valor       AS valor,
                    lr.descricao   AS lr_descricao,
                    lr.cliente_id  AS cliente_id,
                    c.nome         AS cliente_nome,
                    c.cpf_cnpj     AS cpf_cnpj,
                    c.endereco     AS cliente_endereco,
                    c.numero       AS cliente_numero,
                    c.complemento  AS cliente_complemento,
                    c.bairro       AS cliente_bairro,
                    c.cod_cidade   AS cliente_cod_cidade,
                    c.cep          AS cliente_cep,
                    c.telefone     AS cliente_telefone,
                    c.email        AS cliente_email
             FROM notas_fiscais nf
             LEFT JOIN lancamentos_receber lr ON lr.id = nf.lancamento_id
             LEFT JOIN clientes c             ON c.id  = lr.cliente_id
             WHERE nf.id = ?
             LIMIT 1"
        );
        $st->execute([$notaId]);
        $nota = $st->fetch(PDO::FETCH_ASSOC);

        if (!$nota) {
            throw new RuntimeException("Nota fiscal #{$notaId} não encontrada.");
        }

        // Idempotência: já autorizada → no-op.
        if ($nota['status'] === 'autorizada') {
            return;
        }

        $ambiente  = (string)($settings['ambiente'] ?? Env::get('NFSE_AMBIENTE', 'homologacao') ?: 'homologacao');
        $resultado = $this->nfseService->emitir([
            'nota_id'       => $notaId,
            'os_id'         => (string)$nota['os_id'],
            'nome_cliente'  => (string)($nota['cliente_nome'] ?? 'Consumidor não identificado'),
            'cpf_cnpj'      => preg_replace('/\D/', '', (string)($nota['cpf_cnpj'] ?? '')) ?? '',
            'valor_servico' => (float)($nota['valor'] ?? 0),
            'descricao'     => (string)($nota['lr_descricao'] ?? "Serviço — OS #{$nota['os_id']}"),
            'ambiente'      => $ambiente,
            'cliente_endereco' => (string)($nota['cliente_endereco'] ?? ''),
            'cliente_numero' => (string)($nota['cliente_numero'] ?? ''),
            'cliente_complemento' => (string)($nota['cliente_complemento'] ?? ''),
            'cliente_bairro' => (string)($nota['cliente_bairro'] ?? ''),
            'cliente_cod_cidade' => (string)($nota['cliente_cod_cidade'] ?? ''),
            'cliente_cep' => (string)($nota['cliente_cep'] ?? ''),
            'cliente_telefone' => (string)($nota['cliente_telefone'] ?? ''),
            'cliente_email' => (string)($nota['cliente_email'] ?? ''),
        ]);

        $this->pdo->prepare(
            "UPDATE notas_fiscais
             SET status        = ?,
                 numero        = ?,
                 protocolo     = ?,
                 xml_retorno   = ?,
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

        if ($resultado['status'] !== 'autorizada') {
            error_log(
                "[EmitirNfseJob] Nota #{$notaId} terminou com status '{$resultado['status']}' — " .
                'verifique a configuração em NfseService.php'
            );
        }
    }
}

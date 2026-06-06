<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

/**
 * Serviço de Aceite Digital do Termo de Responsabilidade.
 *
 * Gera um slug público (32-hex) para cada OS, permitindo que o cliente
 * acesse a página /termo/{slug}, leia o termo completo e confirme o aceite.
 * O aceite fica registrado com IP, user-agent e timestamp.
 */
final class TermoService
{
    public function __construct(
        private readonly ?PDO $pdo = null,
        private readonly TemplateService $templateService = new TemplateService(),
    ) {}

    private function pdo(): PDO
    {
        return $this->pdo ?? Database::pdo();
    }

    /**
     * Cria um registro de aceite pendente para uma OS.
     * Salva um snapshot do texto atual do termo (para rastreabilidade jurídica).
     *
     * @return string O slug gerado (32 caracteres hex)
     */
    public function criarAceite(string $osId): string
    {
        // Verifica se já existe aceite para esta OS
        $existente = $this->buscarPorOsId($osId);
        if ($existente !== null) {
            return $existente['slug'];
        }

        $slug = bin2hex(random_bytes(16)); // 32 chars hex
        $termoTexto = $this->templateService->render('termo_responsabilidade', []);

        $stmt = $this->pdo()->prepare(
            "INSERT INTO termos_aceite (os_id, slug, versao_termo, created_at)
             VALUES (:os_id, :slug, :versao_termo, NOW())"
        );
        $stmt->execute([
            ':os_id'        => $osId,
            ':slug'         => $slug,
            ':versao_termo' => $termoTexto,
        ]);

        return $slug;
    }

    /**
     * Busca um aceite pelo slug público (para a página /termo/{slug}).
     * Retorna null se o slug não existir.
     *
     * @return array{id:int, os_id:string, slug:string, versao_termo:string, aceito_em:?string, ip_cliente:?string, user_agent:?string, created_at:string}|null
     */
    public function buscarPorSlug(string $slug): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT ta.*, os.nome_cliente, os.telefone, os.doc_cliente, os.data_entrada, os.status AS os_status
             FROM termos_aceite ta
             JOIN ordem_servico os ON os.id = ta.os_id
             WHERE ta.slug = :slug
             LIMIT 1"
        );
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Busca aceite pela OS (para exibir status na tela de detalhe).
     */
    public function buscarPorOsId(string $osId): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT * FROM termos_aceite WHERE os_id = :os_id ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':os_id' => $osId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Registra o aceite do cliente (grava IP, user-agent e timestamp).
     * Retorna false se já foi aceito (idempotente, sem erro).
     */
    public function registrarAceite(string $slug, string $ip, string $userAgent): bool
    {
        $aceite = $this->buscarPorSlug($slug);
        if ($aceite === null) {
            return false;
        }

        // Já aceito? Retorna true sem alterar (idempotente)
        if ($aceite['aceito_em'] !== null) {
            return true;
        }

        $stmt = $this->pdo()->prepare(
            "UPDATE termos_aceite
             SET aceito_em = NOW(),
                 ip_cliente = :ip,
                 user_agent = :ua
             WHERE slug = :slug AND aceito_em IS NULL
             LIMIT 1"
        );
        $stmt->execute([
            ':ip'   => $ip,
            ':ua'   => mb_substr($userAgent, 0, 512),
            ':slug' => $slug,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Gera a URL pública completa do termo.
     */
    public function gerarUrl(string $slug): string
    {
        $base = rtrim($_ENV['APP_URL'] ?? '', '/');
        if ($base === '') {
            // Fallback: tenta construir a partir do request atual
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $base  = "{$proto}://{$host}";
        }
        return "{$base}/termo/{$slug}";
    }

    /**
     * Busca equipamentos de uma OS (para exibir na página do termo).
     */
    public function buscarEquipamentosOs(string $osId): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT nome, serie, defeito, voltagem FROM os_equipamento
             WHERE os_id = :os_id ORDER BY ordem_idx ASC"
        );
        $stmt->execute([':os_id' => $osId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

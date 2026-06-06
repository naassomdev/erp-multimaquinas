<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\ClienteRepository;
use DomainException;

/**
 * Tela administrativa de detecção e mesclagem de clientes duplicados (11B-2 / 11B-3).
 */
final class ClienteDuplicadosController
{
    private const CAMPOS_COPIÁVEIS = [
        'nome', 'nome_fantasia', 'cpf_cnpj', 'telefone', 'telefone2',
        'celular', 'fone', 'email', 'endereco', 'numero', 'complemento',
        'bairro', 'cidade', 'uf', 'cep',
    ];

    public function __construct(
        private readonly ClienteRepository $repo = new ClienteRepository(),
    ) {}

    // ── GET /admin/clientes/duplicados ─────────────────────────────────────
    public function index(Request $request): Response
    {
        $this->assertAdmin();

        $candidatos = array_map(
            fn(array $par): array => $this->normalizarCandidatoDuplicado($par),
            $this->repo->listarCandidatosDuplicados()
        );
        $porMotivo  = [];
        foreach ($candidatos as $par) {
            $porMotivo[$par['motivo']][] = $par;
        }

        return Response::html(View::render('admin/clientes_duplicados', [
            'titulo'     => 'Clientes Duplicados',
            'activeMenu' => 'admin_duplicados',
            'candidatos' => $candidatos,
            'porMotivo'  => $porMotivo,
            'totais'     => [
                'total'    => count($candidatos),
                'cpf'      => count($porMotivo['CPF/CNPJ igual']         ?? []),
                'email'    => count($porMotivo['E-mail igual']           ?? []),
                'telefone' => count($porMotivo['Telefone/celular igual'] ?? []),
            ],
        ]));
    }

    // ── GET /admin/clientes/{origem}/mesclar-em/{destino} ─────────────────
    public function comparar(Request $request, string $origem, string $destino): Response
    {
        $this->assertAdmin();

        $origemId   = (int) $origem;
        $destinoId  = (int) $destino;

        $cliOrigem  = $this->repo->buscarParaMesclagem($origemId);
        $cliDestino = $this->repo->buscarParaMesclagem($destinoId);

        if (!$cliOrigem)  throw new HttpException(404, "Cliente origem #{$origemId} não encontrado.");
        if (!$cliDestino) throw new HttpException(404, "Cliente destino #{$destinoId} não encontrado.");

        $osOrigem  = $this->contarOs($origemId);
        $osDestino = $this->contarOs($destinoId);
        $vinculosOrigem  = $this->contarVinculos($origemId);
        $vinculosDestino = $this->contarVinculos($destinoId);

        return Response::html(View::render('admin/clientes_mesclar', [
            'titulo'        => "Mesclar Clientes #$origemId → #$destinoId",
            'activeMenu'    => 'admin_duplicados',
            'csrf_token'    => Csrf::token(),
            'origem'        => $cliOrigem,
            'destino'       => $cliDestino,
            'osOrigem'      => $osOrigem,
            'osDestino'     => $osDestino,
            'vinculosOrigem'  => $vinculosOrigem,
            'vinculosDestino' => $vinculosDestino,
            'campos'        => self::CAMPOS_COPIÁVEIS,
            'sugestoes'     => $this->sugerirCopias($cliOrigem, $cliDestino),
        ]));
    }

    // ── POST /admin/clientes/{origem}/mesclar-em/{destino} ────────────────
    public function executar(Request $request, string $origem, string $destino): Response
    {
        $this->assertAdmin();

        if (!Csrf::check((string) $request->input('_csrf', ''))) {
            Flash::set('error', 'Sessão expirada. Tente novamente.');
            return Response::redirect("/admin/clientes/{$origem}/mesclar-em/{$destino}");
        }

        // Confirmação textual obrigatória
        $confirmacao = strtoupper(trim((string) $request->input('confirmacao', '')));
        if ($confirmacao !== 'MESCLAR') {
            Flash::set('error', 'Digite MESCLAR no campo de confirmação para prosseguir.');
            return Response::redirect("/admin/clientes/{$origem}/mesclar-em/{$destino}");
        }

        $origemId   = (int) $origem;
        $destinoId  = (int) $destino;

        // Campos selecionados para copiar da origem para o destino
        $camposCopiar = array_intersect(
            (array) $request->input('campos_copiar', []),
            self::CAMPOS_COPIÁVEIS
        );

        $usuario    = Auth::user();
        $operadorId = (int) ($usuario['id'] ?? 0);

        try {
            $afetados = $this->repo->mesclar($origemId, $destinoId, $operadorId, $camposCopiar);
        } catch (DomainException $e) {
            Flash::set('error', $e->getMessage());
            return Response::redirect("/admin/clientes/{$origem}/mesclar-em/{$destino}");
        } catch (\Throwable $e) {
            Flash::set('error', 'Erro inesperado: ' . $e->getMessage());
            return Response::redirect("/admin/clientes/{$origem}/mesclar-em/{$destino}");
        }

        $resumo = "Mesclagem concluída: #{$origemId} → #{$destinoId}. "
            . "OS: {$afetados['os']} · Lançamentos: {$afetados['lancamentos']} · "
            . "Vendas: {$afetados['vendas']} · Notas: {$afetados['notas']}.";
        Flash::set('success', $resumo);

        return Response::redirect('/admin/clientes/duplicados');
    }

    // ── Helpers privados ──────────────────────────────────────────────────

    private function assertAdmin(): void
    {
        if (!Auth::temNivel('admin')) {
            throw new HttpException(403, 'Acesso restrito a administradores.');
        }
    }

    private function contarOs(int $clienteId): int
    {
        $stmt = \App\Core\Database::pdo()->prepare(
            'SELECT COUNT(*) FROM ordem_servico WHERE cliente_id = ?'
        );
        $stmt->execute([$clienteId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Conta vínculos do cliente em tabelas auxiliares (lançamentos, vendas, notas).
     * @return array{lancamentos:int, vendas:int, notas:int}
     */
    private function contarVinculos(int $clienteId): array
    {
        $pdo = \App\Core\Database::pdo();
        $tabelas = [
            'lancamentos' => 'lancamentos_receber',
            'vendas'      => 'vendas_balcao',
            'notas'       => 'notas_fiscais',
        ];
        $result = [];
        foreach ($tabelas as $key => $tabela) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$tabela} WHERE cliente_id = ?");
            $stmt->execute([$clienteId]);
            $result[$key] = (int) $stmt->fetchColumn();
        }
        return $result;
    }

    /**
     * Retorna array de campos onde origem tem valor e destino está vazio → sugerir cópia.
     * @return array<string, bool>  campo → true se deve ser sugerido
     */
    private function sugerirCopias(array $origem, array $destino): array
    {
        $sugestoes = [];
        foreach (self::CAMPOS_COPIÁVEIS as $campo) {
            $valOrig  = trim((string)($origem[$campo]  ?? ''));
            $valDest  = trim((string)($destino[$campo] ?? ''));
            $sugestoes[$campo] = ($valOrig !== '' && $valDest === '');
        }
        return $sugestoes;
    }

    /**
     * Garante que a view receba todas as chaves esperadas para cada par.
     *
     * @param array<string, mixed> $par
     * @return array<string, mixed>
     */
    private function normalizarCandidatoDuplicado(array $par): array
    {
        $normalizado = array_merge([
            'id_a' => 0,
            'nome_a' => '',
            'fantasia_a' => '',
            'cpf_a' => '',
            'email_a' => '',
            'telefone_a' => '',
            'celular_a' => '',
            'os_a' => 0,
            'created_a' => '',
            'id_b' => 0,
            'nome_b' => '',
            'fantasia_b' => '',
            'cpf_b' => '',
            'email_b' => '',
            'telefone_b' => '',
            'celular_b' => '',
            'os_b' => 0,
            'created_b' => '',
            'motivo' => 'Sem critério',
        ], $par);

        $normalizado['id_a'] = (int) $normalizado['id_a'];
        $normalizado['id_b'] = (int) $normalizado['id_b'];
        $normalizado['os_a'] = (int) $normalizado['os_a'];
        $normalizado['os_b'] = (int) $normalizado['os_b'];
        $normalizado['motivo'] = trim((string) $normalizado['motivo']) ?: 'Sem critério';

        return $normalizado;
    }
}

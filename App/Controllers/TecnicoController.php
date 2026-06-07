<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\NotificacaoTecnicoRepository;
use App\Repositories\OrcamentoRepository;
use App\Repositories\OrdemServicoRepository;
use App\Repositories\OsEquipamentoRepository;
use App\Repositories\TecnicoItemRepository;
use App\Repositories\NecessidadeCompraRepository;
use App\Services\AuditoriaService;
use App\Services\CatalogSourceSettingsService;
use App\Services\TecnicoService;

final class TecnicoController
{
    private const PER_PAGE = 30;

    public function __construct(
        private readonly OsEquipamentoRepository      $equipRepo  = new OsEquipamentoRepository(),
        private readonly NotificacaoTecnicoRepository $notifRepo  = new NotificacaoTecnicoRepository(),
        private readonly OrdemServicoRepository       $osRepo     = new OrdemServicoRepository(),
        private readonly OrcamentoRepository          $orcRepo    = new OrcamentoRepository(),
        private readonly TecnicoItemRepository        $itensRepo  = new TecnicoItemRepository(),
        private readonly CatalogSourceSettingsService  $catalogSettings   = new CatalogSourceSettingsService(),
        private readonly AuditoriaService              $audit             = new AuditoriaService(),
        private readonly TecnicoService                $tecnicoService    = new TecnicoService(),
        private readonly NecessidadeCompraRepository   $necessidadeRepo   = new NecessidadeCompraRepository(),
    ) {}

    public function index(Request $request): Response
    {
        $filtros = [
            'status'   => $this->normalizarStatus((string) $request->input('status', 'todos')),
            'garantia' => $this->normalizarGarantia((string) $request->input('garantia', '')),
            'busca'    => trim((string) $request->input('q', '')),
        ];

        $page = max(1, (int) $request->input('p', 1));

        $items = $this->equipRepo->listarComFiltros($filtros, $page, self::PER_PAGE);
        $total = $this->equipRepo->contarComFiltros($filtros);

        $totalPages = (int) max(1, (int) ceil($total / self::PER_PAGE));
        if ($page > $totalPages) $page = $totalPages;

        $notifs = $this->notifRepo->contarNaoLidas();

        return Response::html(View::render('tecnico/index', [
            'titulo'      => 'Painel do Técnico',
            'activeMenu'  => 'tecnico',
            'usuario'     => Auth::user(),
            'items'       => $items,
            'filtros'     => $filtros,
            'paginacao'   => [
                'page'        => $page,
                'per_page'    => self::PER_PAGE,
                'total'       => $total,
                'total_pages' => $totalPages,
            ],
            'notif_count' => $notifs,
        ]));
    }

    public function detalhe(Request $request, string $os_id, string $idx): Response
    {
        $equipIdx = (int) $idx;

        $equip = $this->equipRepo->buscar($os_id, $equipIdx);
        if ($equip === null) {
            throw new HttpException(404, "Equipamento {$os_id}#{$equipIdx} não encontrado");
        }

        $os = $this->osRepo->buscarPorId($os_id);
        if ($os === null) {
            throw new HttpException(404, "OS {$os_id} não encontrada");
        }

        if (Auth::temNivel('oficina')) {
            $this->notifRepo->marcarLidasPorOsEquipTipos(
                $os_id,
                $equipIdx,
                ['aprovado', 'cancelado', 'descarte'],
                'oficina'
            );
        }

        $equipamentosOs = $this->equipRepo->listarPorOs($os_id);

        // Transição automática: aberta → andamento ao abrir o detalhe técnico.
        // Falha silenciosa para não bloquear a renderização da tela.
        if ((string) ($equip['status_equip'] ?? '') === 'aberta') {
            try {
                $this->tecnicoService->iniciarDiagnostico($os_id, $equipIdx);
                $equip = $this->equipRepo->buscar($os_id, $equipIdx) ?? $equip;
            } catch (Throwable) {
                // Ignora — o equipamento permanece com o status original na tela
            }
        }

        $itens     = $this->necessidadeRepo->anexarStatusAosItens(
            $this->itensRepo->listarPorEquipamento($os_id, $equipIdx)
        );
        $orcamento = $this->orcRepo->buscarPorOsEquip($os_id, $equipIdx);
        $orcItens  = $orcamento !== null
            ? $this->orcRepo->listarItens((int) $orcamento['id'])
            : [];

        $totalItens = 0.0;
        foreach ($itens as $i) $totalItens += (float) ($i['valor_total'] ?? 0);

        // Fotos do técnico (fotos_json) — específicas deste equipamento
        $fotosDecoded = json_decode((string) ($equip['fotos_json'] ?? '[]'), true);
        $fotosTecnico = is_array($fotosDecoded)
            ? array_values(array_filter($fotosDecoded, 'is_string'))
            : [];

        // Fotos da recepção (fotos_os_json) — armazenadas sempre no equip[0]
        // Se estamos vendo outro equipamento, buscamos do equip[0].
        $fotosRecepcao = [];
        if ($equipIdx === 0) {
            $fotosOsDecoded = json_decode((string) ($equip['fotos_os_json'] ?? '[]'), true);
            $fotosRecepcao = is_array($fotosOsDecoded)
                ? array_values(array_filter($fotosOsDecoded, 'is_string'))
                : [];
        } else {
            $equip0 = $this->equipRepo->buscar($os_id, 0);
            if ($equip0 !== null) {
                $fotosOsDecoded = json_decode((string) ($equip0['fotos_os_json'] ?? '[]'), true);
                $fotosRecepcao = is_array($fotosOsDecoded)
                    ? array_values(array_filter($fotosOsDecoded, 'is_string'))
                    : [];
            }
        }

        $vista = (string) ($equip['vista_explodida'] ?? '');
        $resumoNecessidades = $this->necessidadeRepo->listarResumoPorOs($os_id);
        $necessidadesEquip  = $resumoNecessidades[$equipIdx] ?? [
            'pendentes' => 0, 'compradas_sem_entrada' => 0,
            'manuais_sem_entrada' => 0, 'entradas_feitas' => 0, 'bloqueantes_total' => 0,
        ];

        return Response::html(View::render('tecnico/detalhe', [
            'titulo'                 => "OS {$os_id} · Equipamento #{$equipIdx}",
            'activeMenu'             => 'tecnico',
            'usuario'                => Auth::user(),
            'os'                     => $os,
            'equip'                  => $equip,
            'equipamentos_os'        => $equipamentosOs,
            'itens'                  => $itens,
            'total_itens'            => round($totalItens, 2),
            'orcamento'              => $orcamento,
            'orcamento_itens'        => $orcItens,
            'fotos'                  => $fotosTecnico,
            'fotos_recepcao'         => $fotosRecepcao,
            'vista'                  => $vista,
            'catalogo_fontes'        => $this->catalogSettings->listarAtivas(),
            'necessidades_pendentes' => $necessidadesEquip['bloqueantes_total'],
            'necessidades_resumo'    => $necessidadesEquip,
            'servicos_terceiros'     => $this->tecnicoService->listarServicosTerceirosPorEquipamento($os_id, $equipIdx),
            'csrf_token'             => Csrf::token(),
        ]));
    }

    public function configuracaoCatalogo(Request $request): Response
    {
        return Response::html(View::render('tecnico/catalogo_fontes', [
            'titulo' => 'Técnico - Catálogo de Fontes',
            'activeMenu' => 'tecnico',
            'usuario' => Auth::user(),
            'settings' => $this->catalogSettings->obter(),
            'csrf_token' => Csrf::token(),
        ]));
    }

    public function salvarConfiguracaoCatalogo(Request $request): Response
    {
        if (!Csrf::check((string) $request->input('_csrf', ''))) {
            Flash::set('error', 'Sessão expirada. Tente novamente.');
            return Response::redirect('/tecnico/catalogo-fontes');
        }

        try {
            $this->catalogSettings->salvar($request->body);
            $this->audit->registrar('configuracoes', 'catalogo_fontes', 'UPDATE', [
                'escopo' => 'catalogo_fontes',
            ]);
            Flash::set('success', 'Configuração das fontes do catálogo salva com sucesso.');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }

        return Response::redirect('/tecnico/catalogo-fontes');
    }

    public function configuracoesSistema(Request $request): Response
    {
        $pdo = Database::pdo();
        $stmt = $pdo->query(
            "SELECT chave, valor FROM configuracoes
             WHERE chave IN ('alerta_dias_os_sem_diagnostico')
             ORDER BY chave"
        );

        $configs = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $configs[(string) $row['chave']] = (string) $row['valor'];
        }

        return Response::html(View::render('tecnico/configuracoes_sistema', [
            'titulo'     => 'Configurações do Sistema',
            'activeMenu' => 'tecnico',
            'usuario'    => Auth::user(),
            'configs'    => $configs,
            'csrf_token' => Csrf::token(),
        ]));
    }

    public function salvarConfiguracoesSistema(Request $request): Response
    {
        if (!Csrf::check((string) $request->input('_csrf', ''))) {
            Flash::set('error', 'Sessão expirada. Tente novamente.');
            return Response::redirect('/tecnico/configuracoes-sistema');
        }

        $allowed = [
            'alerta_dias_os_sem_diagnostico' => 'inteiro_positivo',
        ];

        $pdo = Database::pdo();

        try {
            foreach ($allowed as $chave => $tipo) {
                $raw = $request->input($chave);
                if ($raw === null) {
                    continue;
                }

                $valor = match ($tipo) {
                    'inteiro_positivo' => (string) max(1, (int) $raw),
                    default            => trim((string) $raw),
                };

                $stmt = $pdo->prepare(
                    "INSERT INTO configuracoes (chave, valor)
                     VALUES (:chave, :valor)
                     ON DUPLICATE KEY UPDATE valor = :valor2"
                );
                $stmt->execute([
                    ':chave'  => $chave,
                    ':valor'  => $valor,
                    ':valor2' => $valor,
                ]);
            }

            $this->audit->registrar('configuracoes', 'sistema', 'UPDATE', [
                'escopo' => 'configuracoes_sistema',
            ]);
            Flash::set('success', 'Configurações salvas com sucesso.');
        } catch (\Throwable $e) {
            Flash::set('error', 'Erro ao salvar: ' . $e->getMessage());
        }

        return Response::redirect('/tecnico/configuracoes-sistema');
    }

    private function normalizarStatus(string $status): string
    {
        $validos = ['pendentes', 'aberta', 'andamento', 'montagem', 'pronto', 'cancelado', 'todos'];
        return in_array($status, $validos, true) ? $status : 'todos';
    }

    private function normalizarGarantia(string $garantia): string
    {
        return in_array($garantia, ['sim', 'nao'], true) ? $garantia : '';
    }
}

<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\NecessidadeCompraRepository;

final class ComprasController
{
    private const PER_PAGE       = 50;
    private const PER_PAGE_PEDIDO = 500;

    public function __construct(
        private readonly NecessidadeCompraRepository $repo = new NecessidadeCompraRepository(),
    ) {}

    public function necessidades(Request $request): Response
    {
        if (!Auth::temNivel('admin', 'recepcao')) {
            throw new HttpException(403, 'Acesso restrito à recepção e administradores.');
        }

        $filtros = [
            'status'     => trim((string) $request->input('status', 'pendente')),
            'os_id'      => trim((string) $request->input('os_id', '')),
            'q'          => trim((string) $request->input('q', '')),
            'tipo'       => trim((string) $request->input('tipo', '')),
            'fabricante' => mb_strtoupper(trim((string) $request->input('fabricante', '')), 'UTF-8'),
            'agrupar'    => $request->input('agrupar', '') === '1' ? '1' : '',
            'modo'       => $request->input('modo', '') === 'pedido' ? 'pedido' : '',
        ];

        $perPage = $filtros['modo'] === 'pedido' ? self::PER_PAGE_PEDIDO : self::PER_PAGE;
        $page    = max(1, (int) $request->input('p', 1));
        $offset  = ($page - 1) * $perPage;

        $itens                  = $this->repo->listarComFiltros($filtros, $perPage, $offset);
        $total                  = $this->repo->contarComFiltros($filtros);
        $kpis                   = $this->repo->kpis();
        $fabricantesDisponiveis = $this->repo->listarFabricantesDisponiveis();

        $totalPages = (int) max(1, ceil($total / $perPage));
        if ($page > $totalPages) $page = $totalPages;

        return Response::html(View::render('compras/necessidades', [
            'titulo'                 => 'Necessidades de Compra',
            'activeMenu'             => 'compras',
            'usuario'                => Auth::user(),
            'itens'                  => $itens,
            'filtros'                => $filtros,
            'kpis'                   => $kpis,
            'fabricantesDisponiveis' => $fabricantesDisponiveis,
            'paginacao'              => [
                'page'        => $page,
                'per_page'    => self::PER_PAGE,
                'total'       => $total,
                'total_pages' => $totalPages,
            ],
            'csrf_token'             => Csrf::token(),
        ]));
    }
}

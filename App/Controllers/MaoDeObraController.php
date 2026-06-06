<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\MaoDeObraRepository;

final class MaoDeObraController
{
    private MaoDeObraRepository $repo;

    public function __construct()
    {
        $this->repo = new MaoDeObraRepository();
    }

    public function index(Request $request): Response
    {
        $itens = $this->repo->listarTudo();

        return Response::html(View::render('admin/mao_obra', [
            'titulo'     => 'Tabela de Mão de Obra',
            'activeMenu' => 'admin_mo',
            'itens'      => $itens,
            'usuario'    => Auth::user() ?? [],
        ]));
    }

    public function salvar(Request $request): Response
    {
        $id = (int) ($request->input('id') ?? 0);
        $dados = [
            'categoria'    => $request->input('categoria'),
            'nome'         => $request->input('nome'),
            'valor_padrao' => (float) str_replace(',', '.', (string) $request->input('valor_padrao')),
        ];

        if ($id > 0) {
            $this->repo->atualizar($id, $dados);
        } else {
            $this->repo->criar($dados);
        }

        return Response::redirect('/admin/mao-de-obra?success=1');
    }

    public function deletar(Request $request, string $id): Response
    {
        $this->repo->deletar((int) $id);
        return Response::redirect('/admin/mao-de-obra?success=1');
    }
}

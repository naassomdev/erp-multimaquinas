<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\OrdemServicoRepository;

final class OrdemServicoApiController
{
    public function __construct(
        private readonly OrdemServicoRepository $osRepo = new OrdemServicoRepository(),
    ) {}

    public function buscarPorTelefone(Request $request): Response
    {
        $telefone = trim((string) $request->input('telefone', ''));
        if ($telefone === '') {
            return Response::json(['ok' => false, 'error' => 'Telefone obrigatório'], 400);
        }

        $ordens = $this->osRepo->buscarComResumoPorTelefone($telefone);
        return Response::json([
            'ok'    => true,
            'total' => count($ordens),
            'ordens'=> $ordens,
        ]);
    }
}

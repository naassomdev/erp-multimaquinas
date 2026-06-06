<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\ClienteRepository;
use App\Services\ClienteDocumentoLookupException;
use App\Services\ClienteDocumentoLookupService;

final class ClienteApiController
{
    public function __construct(
        private readonly ClienteRepository $repo = new ClienteRepository(),
        private readonly ClienteDocumentoLookupService $documentos = new ClienteDocumentoLookupService(),
    ) {}

    /**
     * GET /api/clientes/busca?q=...
     *
     * Autocomplete para formulários (OS, Vendas, etc.)
     * O frontend usa Debounce de ~350ms + AbortController, então o volume
     * de requests é controlado. Aqui mantemos o mínimo de 2 chars para
     * evitar queries muito amplas (ex: q=J retornaria centenas).
     */
    public function busca(Request $request): Response
    {
        $termo = trim((string) $request->input('q', ''));
        if (mb_strlen($termo) < 2) {
            return Response::json(['ok' => true, 'clientes' => []]);
        }

        $clientes = $this->repo->buscarAutocomplete($termo, 10);

        return Response::json([
            'ok'       => true,
            'clientes' => $clientes,
        ]);
    }

    /**
     * GET /api/clientes/cep/{cep}
     * Consulta ViaCEP e retorna dados do endereço.
     */
    public function buscarPorCep(Request $request, string $cep): Response
    {
        $cep = preg_replace('/\D/', '', $cep);
        if (strlen($cep) !== 8) {
            return Response::json(['ok' => false, 'error' => 'CEP inválido'], 400);
        }

        $url = "https://viacep.com.br/ws/{$cep}/json/";
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 5,
                'method'  => 'GET',
                'header'  => "Accept: application/json\r\n",
            ],
        ]);

        $json = @file_get_contents($url, false, $ctx);
        if ($json === false) {
            return Response::json(['ok' => false, 'error' => 'Não foi possível consultar o CEP'], 502);
        }

        $dados = json_decode($json, true);
        if (!is_array($dados) || isset($dados['erro'])) {
            return Response::json(['ok' => false, 'error' => 'CEP não encontrado'], 404);
        }

        return Response::json([
            'ok'   => true,
            'data' => [
                'cep'        => $dados['cep'] ?? '',
                'endereco'   => $dados['logradouro'] ?? '',
                'complemento'=> $dados['complemento'] ?? '',
                'bairro'     => $dados['bairro'] ?? '',
                'cidade'     => $dados['localidade'] ?? '',
                'uf'         => $dados['uf'] ?? '',
                'cod_cidade' => $dados['ibge'] ?? '',
            ],
        ]);
    }

    /**
     * GET /api/clientes/documento/{doc}
     * Consulta CPF na CPFHub ou CNPJ no provedor configurado.
     */
    public function buscarPorDocumento(Request $request, string $doc): Response
    {
        $docLimpo = preg_replace('/\D/', '', $doc);
        $len = strlen($docLimpo);

        try {
            if ($len === 11) {
                return Response::json([
                    'ok' => true,
                    'data' => $this->documentos->consultarCpf($docLimpo),
                ]);
            }

            if ($len === 14) {
                return Response::json([
                    'ok' => true,
                    'data' => $this->documentos->consultarCnpj($docLimpo),
                ]);
            }
        } catch (ClienteDocumentoLookupException $e) {
            return Response::json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], $e->statusCode());
        }

        return Response::json(['ok' => false, 'error' => 'Documento inválido. Informe CPF (11) ou CNPJ (14 dígitos).'], 400);
    }

    /**
     * POST /api/clientes/verificar-duplicado
     * 
     * Verifica possíveis clientes duplicados com base nos dados do formulário.
     * Retorna array de sugestões.
     */
    public function verificarDuplicado(Request $request): Response
    {
        $dados = [
            'cpf_cnpj' => $request->input('cpf_cnpj', ''),
            'email'    => $request->input('email', ''),
            'telefone' => $request->input('telefone', ''),
            'telefone2'=> $request->input('telefone2', ''),
            'celular'  => $request->input('celular', ''),
            'fone'     => $request->input('fone', ''),
        ];
        
        $ignorarId = $request->input('id');
        $ignorarId = is_numeric($ignorarId) ? (int) $ignorarId : null;
        
        $duplicados = $this->repo->buscarPossiveisDuplicadosCadastro($dados, $ignorarId);

        return Response::json([
            'ok' => true,
            'duplicados' => $duplicados
        ]);
    }
}

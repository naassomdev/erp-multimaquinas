<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ConfiguracaoRepository;
use App\Repositories\OrcamentoRepository;
use App\Services\EmailService;
use App\Services\OrcamentoPdfService;
use App\Services\OrcamentoService;
use App\Services\TemplateService;
use InvalidArgumentException;
use PHPMailer\PHPMailer\Exception as MailerException;
use Throwable;

final class OrcamentoApiController
{
    public function __construct(
        private readonly OrcamentoService    $service = new OrcamentoService(),
        private readonly OrcamentoRepository $repo    = new OrcamentoRepository(),
    ) {}

    public function listarPorOs(Request $request, string $os_id): Response
    {
        if (!Auth::temNivel('admin', 'recepcao')) {
            return Response::json([
                'ok'    => false,
                'error' => 'Acesso negado. Apenas recepção e administradores podem gerenciar orçamentos.',
            ], 403);
        }

        return Response::json([
            'ok'         => true,
            'orcamentos' => $this->service->listarPorOs($os_id),
        ]);
    }

    public function salvar(Request $request): Response
    {
        if (!Auth::temNivel('admin', 'recepcao')) {
            return Response::json([
                'ok'    => false,
                'error' => 'Acesso negado. Apenas recepção e administradores podem gerenciar orçamentos.',
            ], 403);
        }

        $osId      = trim((string) $request->input('os_id', ''));
        $equipIdx  = (int) $request->input('equip_idx', -1);
        $cabecalho = $request->input('cabecalho');
        $itens     = $request->input('itens');

        if ($osId === '' || $equipIdx < 0) {
            return Response::json(['ok' => false, 'error' => 'os_id e equip_idx obrigatórios'], 400);
        }
        if (!is_array($cabecalho)) $cabecalho = [];
        if (!is_array($itens))     $itens     = [];

        try {
            $id = $this->service->salvarCompleto($osId, $equipIdx, $cabecalho, $itens);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        // 9J-1: motivo_gratuidade é metadado independente — salvo via atualizarCampos após salvarCompleto.
        // Presente apenas quando total = 0 e o select aparece na UI; ausente (null) preserva o valor atual.
        $body = is_array($request->body) ? $request->body : [];
        if (array_key_exists('motivo_gratuidade', $body)) {
            $mv = ($body['motivo_gratuidade'] === '' || $body['motivo_gratuidade'] === null)
                ? null
                : $body['motivo_gratuidade'];
            try {
                $this->service->atualizarCampos($id, ['motivo_gratuidade' => $mv]);
            } catch (InvalidArgumentException $e) {
                // Valor inválido não bloqueia o salvamento — apenas loga.
                error_log("[OrcamentoApiController] motivo_gratuidade inválido para orc {$id}: " . $e->getMessage());
            }
        }

        $orcamentos = $this->service->listarPorOs($osId);
        $atual = null;
        foreach ($orcamentos as $o) {
            if ((int) $o['id'] === $id) { $atual = $o; break; }
        }

        return Response::json([
            'ok'        => true,
            'id'        => $id,
            'orcamento' => $atual,
        ]);
    }

    public function atualizarParcial(Request $request, string $id): Response
    {
        if (!Auth::temNivel('admin', 'recepcao')) {
            return Response::json([
                'ok'    => false,
                'error' => 'Acesso negado. Apenas recepção e administradores podem gerenciar orçamentos.',
            ], 403);
        }

        $orcId  = (int) $id;
        $campos = is_array($request->body) ? $request->body : [];

        try {
            $aplicados = $this->service->atualizarCampos($orcId, $campos);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        $orcamento = $this->repo->buscarPorId($orcId);
        return Response::json([
            'ok'        => true,
            'aplicados' => $aplicados,
            'orcamento' => $orcamento,
        ]);
    }

    public function reverterCancelamento(Request $request, string $id): Response
    {
        if (!Auth::temNivel('admin')) {
            return Response::json([
                'ok'    => false,
                'error' => 'Acesso restrito a administradores.',
            ], 403);
        }

        $orcId = (int) $id;
        $motivo = trim((string) $request->input('motivo', ''));
        if ($motivo === '') {
            return Response::json(['ok' => false, 'error' => 'Informe o motivo da reversão.'], 400);
        }

        try {
            $resultado = $this->service->reverterCancelamento($orcId, $motivo, Auth::id() ?? 0);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => 'Erro interno ao reverter cancelamento.'], 500);
        }

        return Response::json([
            'ok'        => true,
            'resultado' => $resultado,
            'orcamento' => $this->repo->buscarPorId($orcId),
        ]);
    }

    public function retiradaSemCusto(Request $request, string $id): Response
    {
        if (!Auth::temNivel('admin', 'recepcao')) {
            return Response::json([
                'ok'    => false,
                'error' => 'Acesso negado. Apenas recepção e administradores podem registrar retirada sem custo.',
            ], 403);
        }

        $orcId = (int) $id;

        try {
            $resultado = $this->service->registrarRetiradaSemCusto($orcId, Auth::id() ?? 0);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => 'Erro interno ao registrar retirada sem custo.'], 500);
        }

        return Response::json([
            'ok'        => true,
            'resultado' => $resultado,
            'orcamento' => $this->repo->buscarPorId($orcId),
        ]);
    }

    public function pecasFornecidasCliente(Request $request, string $id): Response
    {
        if (!Auth::temNivel('admin', 'recepcao')) {
            return Response::json([
                'ok'    => false,
                'error' => 'Acesso negado. Apenas recepção e administradores podem confirmar peças fornecidas pelo cliente.',
            ], 403);
        }

        $orcId = (int) $id;
        $itemIdsRaw = $request->input('item_ids', []);
        $itemIds = [];
        if (is_array($itemIdsRaw)) {
            foreach ($itemIdsRaw as $itemId) {
                $itemId = (int) $itemId;
                if ($itemId > 0) {
                    $itemIds[] = $itemId;
                }
            }
        }
        $itemIds = array_values(array_unique($itemIds));

        $motivo = trim((string) $request->input('motivo', 'Cliente trouxe as peças'));
        if ($motivo === '') {
            $motivo = 'Cliente trouxe as peças';
        }

        $liberarMontagem = filter_var(
            $request->input('liberar_montagem', true),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );
        $liberarMontagem = $liberarMontagem !== false;

        try {
            $resultado = $this->service->marcarPecasFornecidasCliente(
                $orcId,
                $itemIds,
                $motivo,
                $liberarMontagem,
                Auth::id() ?? 0,
            );
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => 'Erro interno ao confirmar peças fornecidas pelo cliente.'], 500);
        }

        return Response::json([
            'ok'        => true,
            'resultado' => $resultado,
        ]);
    }

    public function whatsapp(Request $request, string $id): Response
    {
        if (!Auth::temNivel('admin', 'recepcao')) {
            return Response::json([
                'ok'    => false,
                'error' => 'Acesso negado. Apenas recepção e administradores podem gerar o link de WhatsApp.',
            ], 403);
        }

        $orcId    = (int) $id;
        $confirmou = filter_var($request->input('confirmar_itens_zerados', false), FILTER_VALIDATE_BOOLEAN);
        $registrarEnvio = filter_var($request->input('registrar_envio', false), FILTER_VALIDATE_BOOLEAN);

        // Verificar itens zerados antes de gerar o link.
        // Se houver itens com valor_unit <= 0 e o usuário não confirmou, exige confirmação.
        if (!$confirmou) {
            $itens = $this->repo->listarItens($orcId);
            $zerados = array_filter($itens, static function (array $item): bool {
                return ((float) ($item['valor_unit'] ?? 0)) <= 0
                    || ((float) ($item['valor_total'] ?? 0)) <= 0;
            });
            if (!empty($zerados)) {
                $qtd = count($zerados);
                return Response::json([
                    'ok'               => false,
                    'needs_confirmation' => true,
                    'qtd_zerados'      => $qtd,
                    'error'            => "Este orçamento possui {$qtd} item(s) com valor zero. Revise antes de enviar ou confirme o envio mesmo assim.",
                ]);
            }
        }

        try {
            $dados = $this->service->gerarDadosWhatsapp($orcId, $registrarEnvio);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        $orcamento = $this->repo->buscarPorId($orcId);
        return Response::json([
            'ok'        => true,
            'mensagem'  => $dados['mensagem'],
            'telefone'  => $dados['telefone'],
            'wpp_url'   => $dados['wpp_url'],
            'orcamento' => $orcamento,
        ]);
    }

    public function preAprovar(Request $request): Response
    {
        if (!Auth::temNivel('admin', 'recepcao')) {
            return Response::json([
                'ok'    => false,
                'error' => 'Acesso negado. Apenas recepção e administradores podem pré-aprovar orçamentos.',
            ], 403);
        }

        $osId     = trim((string) $request->input('os_id', ''));
        $equipIdx = (int) $request->input('equip_idx', -1);

        if ($osId === '' || $equipIdx < 0) {
            return Response::json(['ok' => false, 'error' => 'Parâmetros inválidos'], 400);
        }

        try {
            $id = $this->service->preAprovar($osId, $equipIdx);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        $orcamento = $this->repo->buscarPorId($id);
        return Response::json(['ok' => true, 'id' => $id, 'orcamento' => $orcamento]);
    }

    // 10C-3: envio do orçamento formal por e-mail com PDF em anexo.
    public function email(Request $request, string $id): Response
    {
        if (!Auth::temNivel('admin', 'recepcao')) {
            return Response::json(['ok' => false, 'error' => 'Acesso negado.'], 403);
        }

        $orcId = (int) $id;

        $dados = $this->repo->buscarParaDocumento($orcId);
        if ($dados === null) {
            return Response::json(['ok' => false, 'error' => 'Orçamento não encontrado.'], 404);
        }

        // Status guard — apenas rascunho/enviado.
        $statusOrc   = (string) ($dados['status']       ?? '');
        $statusEquip = (string) ($dados['status_equip'] ?? '');
        if (!in_array($statusOrc, ['rascunho', 'enviado'], true)) {
            return Response::json([
                'ok'    => false,
                'error' => "Não é possível enviar orçamento com status '{$statusOrc}' por e-mail.",
            ], 400);
        }
        $equipFinalizado = in_array($statusEquip, ['retirado', 'devolvido', 'descartado'], true);
        if ($equipFinalizado) {
            return Response::json([
                'ok'    => false,
                'error' => 'Equipamento já finalizado — envio não permitido.',
            ], 400);
        }

        // Validar e-mail do cliente.
        $cliEmail = trim((string) ($dados['cli_email'] ?? ''));
        if ($cliEmail === '' || filter_var($cliEmail, FILTER_VALIDATE_EMAIL) === false) {
            return Response::json([
                'ok'    => false,
                'error' => 'Cliente não possui e-mail válido cadastrado.',
            ], 400);
        }

        // Verificar SMTP configurado.
        $emailSvc = new EmailService();
        if (!$emailSvc->configurado()) {
            return Response::json([
                'ok'    => false,
                'error' => 'SMTP não está configurado. Acesse Admin → Config. E-mail.',
            ], 400);
        }

        // Vars do template.
        $cfgEmp         = (new ConfiguracaoRepository())->listarPorPrefixo('empresa_');
        $empresaTelefone = $cfgEmp['empresa_telefone'] ?? '';
        $user            = Auth::user();
        $remetenteNome   = $this->primeiroNome((string) ($user['nome'] ?? 'Multimáquinas'));
        $total           = (float) ($dados['total'] ?? 0);

        $rendered = (new TemplateService())->renderFull('orcamento_os_email', [
            'cliente_nome'     => (string) ($dados['cli_nome'] ?? $dados['nome_cliente'] ?? ''),
            'equipamento_nome' => (string) ($dados['equip_nome'] ?? ''),
            'os_id'            => (string) ($dados['os_id'] ?? ''),
            'total_brl'        => 'R$ ' . number_format($total, 2, ',', '.'),
            'validade'         => (new \DateTime())->modify('+15 days')->format('d/m/Y'),
            'empresa_telefone' => $empresaTelefone,
            'remetente_nome'   => $remetenteNome,
        ]);

        // Gerar PDF.
        $pdfBytes = (new OrcamentoPdfService())->gerarPdfBytes($orcId);

        // Enviar.
        try {
            $nomeAnexo = 'orcamento_OS' . ($dados['os_id'] ?? $orcId)
                       . '_equip' . ($dados['equip_idx'] ?? 0) . '.pdf';
            $emailSvc->enviar($cliEmail, $rendered['assunto'], $rendered['corpo'], [
                [
                    'content' => $pdfBytes,
                    'name'    => $nomeAnexo,
                    'mime'    => 'application/pdf',
                ],
            ]);
        } catch (MailerException) {
            return Response::json([
                'ok'    => false,
                'error' => 'Falha no envio SMTP. Verifique as configurações de e-mail.',
            ], 500);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        // Rascunho → enviado após envio bem-sucedido.
        if ($statusOrc === 'rascunho') {
            $this->repo->atualizarParcial($orcId, ['status' => 'enviado']);
        }

        $orcamento = $this->repo->buscarPorId($orcId);
        return Response::json(['ok' => true, 'email' => $cliEmail, 'orcamento' => $orcamento]);
    }

    private function primeiroNome(string $nome): string
    {
        $partes = explode(' ', trim($nome));
        return $partes[0] !== '' ? $partes[0] : $nome;
    }
}

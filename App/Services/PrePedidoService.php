<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Persistência leve de pré-pedidos (orçamentos rápidos da recepção).
 *
 * Sem nova migration: cada pré-pedido vira um JSON em storage/pre_pedidos/.
 * O slug aleatório (16 hex) serve de magic-link público — o cliente abre pelo
 * WhatsApp/e-mail e vê o orçamento num HTML A4 imprimível.
 *
 * Para evoluir depois (relatórios, listagem por cliente, etc.) basta migrar
 * o conteúdo para uma tabela `pre_pedidos` mantendo a mesma estrutura.
 */
final class PrePedidoService
{
    private string $storagePath;

    public function __construct(?string $storagePath = null)
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $this->storagePath = $storagePath ?? ($base . '/storage/pre_pedidos');
        if (!is_dir($this->storagePath)) {
            if (!@mkdir($this->storagePath, 0775, true) && !is_dir($this->storagePath)) {
                throw new RuntimeException("Não foi possível criar storage/pre_pedidos");
            }
        }
    }

    /**
     * Cria um pré-pedido. $foto é opcional (URL retornada pelo UploadService).
     *
     * @param array{
     *   nome:string, telefone:string, email:string,
     *   descricao:string, qtd:int, valor:float,
     *   vantagens?:array<int, string>, aplicacoes?:array<int, string>,
     *   especificacoes?:array<int, array{chave:string, valor:string}>,
     *   prazo_entrega?:string, cond_pagamento?:string, validade_orcamento?:string,
     *   foto_url:?string, criado_por:?string
     * } $dados
     * @return array{slug:string, numero:string, total:float, criado_em:string}
     */
    public function criar(array $dados): array
    {
        $slug    = bin2hex(random_bytes(8));
        $numero  = $this->proximoNumero();
        $total   = round((float)$dados['valor'] * (int)$dados['qtd'], 2);
        $agora   = date('Y-m-d H:i:s');

        $registro = [
            'slug'        => $slug,
            'numero'      => $numero,
            'criado_em'   => $agora,
            'criado_por'  => $dados['criado_por'] ?? '',
            'cliente'     => [
                'nome'     => trim((string)$dados['nome']),
                'telefone' => trim((string)$dados['telefone']),
                'email'    => trim((string)$dados['email']),
            ],
            'item'        => [
                'descricao' => trim((string)$dados['descricao']),
                'qtd'       => max(1, (int)$dados['qtd']),
                'valor'     => round((float)$dados['valor'], 2),
                'total'     => $total,
            ],
            'detalhes'    => [
                'vantagens'     => array_values($dados['vantagens'] ?? []),
                'aplicacoes'    => array_values($dados['aplicacoes'] ?? []),
                'especificacoes'=> array_values($dados['especificacoes'] ?? []),
            ],
            'condicoes'   => [
                'prazo_entrega'      => trim((string)($dados['prazo_entrega'] ?? 'Sob consulta')),
                'cond_pagamento'     => trim((string)($dados['cond_pagamento'] ?? 'A combinar')),
                'validade_orcamento' => trim((string)($dados['validade_orcamento'] ?? 'Orçamento válido por 15 dias')),
            ],
            'foto_url'    => $dados['foto_url'] ?? null,
        ];

        $path = $this->pathFromSlug($slug);
        if (file_put_contents($path, json_encode($registro, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
            throw new RuntimeException('Falha ao gravar pré-pedido em disco.');
        }

        return [
            'slug'      => $slug,
            'numero'    => $numero,
            'total'     => $total,
            'criado_em' => $agora,
        ];
    }

    /**
     * Lê um pré-pedido pelo slug. Retorna null se não existir.
     */
    public function carregar(string $slug): ?array
    {
        if (!preg_match('/^[a-f0-9]{16}$/', $slug)) return null;
        $path = $this->pathFromSlug($slug);
        if (!is_readable($path)) return null;

        $raw = file_get_contents($path);
        if ($raw === false) return null;

        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Monta a URL do WhatsApp (wa.me) com mensagem cordial pré-formatada.
     */
    public function montarLinkWhatsapp(array $registro, string $linkPublico): string
    {
        $telefone = preg_replace('/\D/', '', (string)($registro['cliente']['telefone'] ?? ''));
        if ($telefone !== '' && !str_starts_with($telefone, '55')) {
            $telefone = '55' . $telefone;
        }

        $msg = $this->montarMensagem($registro, $linkPublico);

        $base = $telefone !== ''
            ? 'https://wa.me/' . $telefone . '?text='
            : 'https://wa.me/?text=';

        return $base . rawurlencode($msg);
    }

    /**
     * Monta o link mailto: com assunto e corpo pré-preenchidos.
     */
    public function montarLinkMailto(array $registro, string $linkPublico): string
    {
        $email = trim((string)($registro['cliente']['email'] ?? ''));
        if ($email === '') $email = '';

        $assunto = "Orçamento Multimáquinas Nº {$registro['numero']}";
        $corpo   = $this->montarMensagem($registro, $linkPublico);

        $params = http_build_query([
            'subject' => $assunto,
            'body'    => $corpo,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'mailto:' . rawurlencode($email) . '?' . $params;
    }

    /**
     * Mensagem padrão usada em WhatsApp e e-mail.
     */
    public function montarMensagem(array $registro, string $linkPublico): string
    {
        $nome     = trim((string)($registro['cliente']['nome'] ?? ''));
        $primeiro = $nome !== '' ? (strtok($nome, ' ') ?: '') : '';
        $sauda    = $primeiro !== '' ? "Olá, {$primeiro}!" : 'Olá!';

        $item  = (string)($registro['item']['descricao'] ?? '');
        $qtd   = (int)($registro['item']['qtd']  ?? 0);
        $total = number_format((float)($registro['item']['total'] ?? 0), 2, ',', '.');
        $validade = trim((string)($registro['condicoes']['validade_orcamento'] ?? ''));
        if ($validade === '') {
            $validade = 'Orçamento válido por 15 dias';
        }

        return implode("\n", [
            $sauda,
            '',
            "Segue seu orçamento Multimáquinas — Nº {$registro['numero']}.",
            '',
            "• Item: {$item}",
            "• Quantidade: {$qtd}",
            "• Total: R\$ {$total}",
            '',
            'Visualize / imprima o orçamento completo neste link:',
            $linkPublico,
            '',
            "Termos: {$validade}, sujeito à disponibilidade em estoque.",
            'Qualquer dúvida estamos à disposição.',
            '',
            '— Multimáquinas Assistência Técnica',
        ]);
    }

    private function pathFromSlug(string $slug): string
    {
        return $this->storagePath . '/' . $slug . '.json';
    }

    /**
     * Próximo número sequencial por ano (NNNN/AAAA). Best-effort: conta os
     * arquivos do ano corrente. Para volume alto, migrar para auto_increment
     * em tabela dedicada.
     */
    private function proximoNumero(): string
    {
        $ano = date('Y');
        $counterFile = $this->storagePath . "/.counter-{$ano}";

        $atual = 0;
        if (is_readable($counterFile)) {
            $atual = (int)file_get_contents($counterFile);
        }
        $proximo = $atual + 1;

        // Lock simples para evitar duas requisições simultâneas pegarem o mesmo nº.
        $fp = @fopen($counterFile, 'c+');
        if ($fp !== false) {
            if (flock($fp, LOCK_EX)) {
                rewind($fp);
                $stored = (int)stream_get_contents($fp);
                $proximo = max($proximo, $stored + 1);
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, (string)$proximo);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
            fclose($fp);
        }

        return str_pad((string)$proximo, 4, '0', STR_PAD_LEFT) . '/' . $ano;
    }
}

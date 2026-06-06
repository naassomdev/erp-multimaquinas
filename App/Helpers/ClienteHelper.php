<?php
declare(strict_types=1);

namespace App\Helpers;

/**
 * Utilitário para padronizar nome de exibição e telefone do cliente
 * em mensagens WhatsApp / notificações.
 *
 * Centraliza a lógica que antes estava duplicada em OrcamentoService
 * e NotificationService, garantindo comportamento consistente.
 */
final class ClienteHelper
{
    /**
     * Resolve o nome de exibição para mensagens ao cliente.
     *
     * Prioridade:
     *   1. nome_fantasia (preenchido e não vazio)
     *   2. nome principal (title case)
     *   3. Fallback: string vazia (caller decide o que fazer)
     *
     * @param array<string, mixed> $cliente  Pode vir de clientes, OS ou orçamento.
     *                                        Chaves tentadas: nome_fantasia, nome, nome_cliente.
     */
    public static function nomeParaMensagem(array $cliente): string
    {
        $fantasia = trim((string) ($cliente['nome_fantasia'] ?? ''));
        if ($fantasia !== '') {
            return self::titleCase($fantasia);
        }

        $nome = trim((string) ($cliente['nome'] ?? $cliente['nome_cliente'] ?? ''));
        if ($nome === '') {
            return '';
        }

        return self::titleCase($nome);
    }

    /**
     * Resolve o melhor telefone para WhatsApp.
     *
     * Prioridade (campo da tabela clientes):
     *   1. celular  — mais provável de ter WhatsApp
     *   2. telefone2
     *   3. fone
     *   4. telefone (fixo / OS-level)
     *
     * Validação mínima: ≥ 10 dígitos (DDD 2 + número 8 ou 9).
     * Retorna número normalizado (só dígitos, DDI 55 prefixado) ou null.
     *
     * @param array<string, mixed> $cliente
     */
    public static function telefoneParaWhatsapp(array $cliente): ?string
    {
        foreach (['celular', 'telefone2', 'fone', 'telefone'] as $campo) {
            $tel = preg_replace('/\D/', '', (string) ($cliente[$campo] ?? ''));
            if ($tel === null || mb_strlen($tel) < 10) {
                continue;
            }
            if (!str_starts_with($tel, '55')) {
                $tel = '55' . $tel;
            }
            return $tel;
        }
        return null;
    }

    /**
     * Saudação por horário do servidor — padrão único para todas as mensagens.
     *
     * 05:00–11:59 → "Bom dia"
     * 12:00–17:59 → "Boa tarde"
     * 18:00–04:59 → "Boa noite"
     */
    public static function saudacaoPorHorario(): string
    {
        $hora = (int) (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('H');
        if ($hora >= 5 && $hora < 12) {
            return 'Bom dia';
        }
        if ($hora >= 12 && $hora < 18) {
            return 'Boa tarde';
        }
        return 'Boa noite';
    }

    // ── Utilitários privados ──────────────────────────────────────────────

    private static function titleCase(string $s): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);
        if (function_exists('mb_convert_case') && function_exists('mb_strtolower')) {
            $s = mb_convert_case(mb_strtolower($s, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
            foreach (['Da', 'De', 'Do', 'Das', 'Dos', 'E'] as $prep) {
                $s = preg_replace('/\b' . $prep . '\b/u', mb_strtolower($prep, 'UTF-8'), $s) ?? $s;
            }
            return $s;
        }

        $s = self::lowercaseUtf8Fallback($s);
        $partes = preg_split('/(\s+)/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($partes === false) {
            return ucwords(strtolower($s));
        }

        $preposicoes = ['da', 'de', 'do', 'das', 'dos', 'e'];
        foreach ($partes as $idx => $parte) {
            if (trim($parte) === '') {
                continue;
            }
            $partes[$idx] = in_array($parte, $preposicoes, true)
                ? $parte
                : self::uppercaseFirstUtf8Fallback($parte);
        }

        return implode('', $partes);
    }

    private static function lowercaseUtf8Fallback(string $s): string
    {
        $s = strtr($s, [
            'Á' => 'á', 'À' => 'à', 'Â' => 'â', 'Ã' => 'ã', 'Ä' => 'ä',
            'É' => 'é', 'È' => 'è', 'Ê' => 'ê', 'Ë' => 'ë',
            'Í' => 'í', 'Ì' => 'ì', 'Î' => 'î', 'Ï' => 'ï',
            'Ó' => 'ó', 'Ò' => 'ò', 'Ô' => 'ô', 'Õ' => 'õ', 'Ö' => 'ö',
            'Ú' => 'ú', 'Ù' => 'ù', 'Û' => 'û', 'Ü' => 'ü',
            'Ç' => 'ç', 'Ñ' => 'ñ',
        ]);
        return strtolower($s);
    }

    private static function uppercaseFirstUtf8Fallback(string $s): string
    {
        if (!preg_match('/^(.)(.*)$/us', $s, $m)) {
            return ucfirst($s);
        }

        $first = strtr(strtoupper($m[1]), [
            'á' => 'Á', 'à' => 'À', 'â' => 'Â', 'ã' => 'Ã', 'ä' => 'Ä',
            'é' => 'É', 'è' => 'È', 'ê' => 'Ê', 'ë' => 'Ë',
            'í' => 'Í', 'ì' => 'Ì', 'î' => 'Î', 'ï' => 'Ï',
            'ó' => 'Ó', 'ò' => 'Ò', 'ô' => 'Ô', 'õ' => 'Õ', 'ö' => 'Ö',
            'ú' => 'Ú', 'ù' => 'Ù', 'û' => 'Û', 'ü' => 'Ü',
            'ç' => 'Ç', 'ñ' => 'Ñ',
        ]);

        return $first . $m[2];
    }
}

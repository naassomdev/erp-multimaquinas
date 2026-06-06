<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use PDO;

final class DashboardController
{
    public function index(Request $request): Response
    {
        $pdo = Database::pdo();

        // Total de clientes
        $totalClientes = (int) $pdo->query('SELECT COUNT(*) FROM clientes')->fetchColumn();

        // OS abertas (não finalizadas)
        $totalOsAbertas = (int) $pdo->query(
            "SELECT COUNT(*) FROM ordem_servico WHERE status IN ('aberta', 'andamento')"
        )->fetchColumn();

        // Orçamentos pendentes (rascunho ou enviado)
        $totalOrcamentos = (int) $pdo->query(
            "SELECT COUNT(*) FROM orcamentos WHERE status IN ('rascunho', 'enviado')"
        )->fetchColumn();

        // OS criadas hoje
        $osHoje = (int) $pdo->query(
            "SELECT COUNT(*) FROM ordem_servico WHERE DATE(created_at) = CURDATE()"
        )->fetchColumn();

        // OS retiradas hoje (faturamento de produção)
        $retiradasHoje = (int) $pdo->query(
            "SELECT COUNT(*) FROM ordem_servico
              WHERE status = 'retirado' AND DATE(data_retirada) = CURDATE()"
        )->fetchColumn();

        // Receita: pagamentos recebidos
        $receitaHoje = (float) $pdo->query(
            "SELECT COALESCE(SUM(valor_pago), 0) FROM lancamentos_receber
              WHERE status = 'pago' AND data_pagamento = CURDATE()"
        )->fetchColumn();

        $receitaMes = (float) $pdo->query(
            "SELECT COALESCE(SUM(valor_pago), 0) FROM lancamentos_receber
              WHERE status = 'pago'
                AND YEAR(data_pagamento) = YEAR(CURDATE())
                AND MONTH(data_pagamento) = MONTH(CURDATE())"
        )->fetchColumn();

        // A receber: aberto + aguardando_fatura
        $aReceber = (float) $pdo->query(
            "SELECT COALESCE(SUM(valor), 0) FROM lancamentos_receber
              WHERE status IN ('aberto', 'aguardando_fatura')"
        )->fetchColumn();

        // Necessidades de compra pendentes (peças sob encomenda)
        $necessidadesPendentes = (int) $pdo->query(
            "SELECT COUNT(*) FROM necessidades_compra WHERE status = 'pendente'"
        )->fetchColumn();

        // Alertas de retirada
        $alertaService = new \App\Services\AlertaRetiradaService();
        $alertaContadores = $alertaService->contarAlertas();

        // Últimas OS para a tabela do dashboard.
        // - Equipamentos vivem em `os_equipamento` (1 OS → N equipamentos); pegamos o primeiro (ordem_idx=0) como rótulo.
        // - O valor exibido aqui é operacional: soma dos orçamentos salvos da OS.
        //   Lançamentos financeiros só existem depois da aprovação/conclusão, então usar
        //   `lancamentos_receber` fazia OS novas aparecerem zeradas indevidamente.
        $ultimasOs = $pdo->query(
            "SELECT
                    os.id,
                    COALESCE(NULLIF(c.nome, ''), os.nome_cliente) AS cliente,
                    (SELECT oe.nome FROM os_equipamento oe
                       WHERE oe.os_id = os.id
                       ORDER BY oe.ordem_idx ASC
                       LIMIT 1) AS equipamento,
                    os.status,
                    os.created_at,
                    (SELECT COALESCE(SUM(o.total), 0) FROM orcamentos o
                       WHERE o.os_id = os.id
                         AND o.status <> 'cancelado') AS valor_total
               FROM ordem_servico os
          LEFT JOIN clientes c ON os.cliente_id = c.id
           ORDER BY os.created_at DESC
              LIMIT 5"
        )->fetchAll(PDO::FETCH_ASSOC);

        return Response::html(View::render('dashboard/index', [
            'titulo'                => 'Dashboard — ERP Multimáquinas',
            'activeMenu'            => 'dashboard',
            'usuario'               => Auth::user(),
            'totalClientes'         => $totalClientes,
            'totalOsAbertas'        => $totalOsAbertas,
            'totalOrcamentos'       => $totalOrcamentos,
            'osHoje'                => $osHoje,
            'retiradasHoje'         => $retiradasHoje,
            'receitaHoje'           => $receitaHoje,
            'receitaMes'            => $receitaMes,
            'aReceber'              => $aReceber,
            'necessidadesPendentes' => $necessidadesPendentes,
            'alertaContadores'      => $alertaContadores,
            'ultimasOs'             => $ultimasOs,
        ]));
    }
}

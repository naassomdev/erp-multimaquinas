    /**
     * Desfaz a retirada de um equipamento.
     *
     * Efeitos:
     *   - status_equip → 'pronto'
     *   - orcamentos: pago = 0, retirado_por = null
     *   - estoque: gera movimentação de 'entrada' estornando os itens
     *   - financeiro: reverte o lancamentos_receber (se houver) para 'aberto' e zera os valores pagos.
     *   - OS: se a OS estiver 'retirado' (pois este foi o último), volta para 'pronto' limpando os dados de retirada.
     *   - OS obs_int: adiciona a justificativa da reversão.
     */
    public function desfazerRetiradaEquipamento(
        string $osId,
        int    $equipIdx,
        int    $operadorId,
        string $justificativa
    ): array {
        $justificativa = trim($justificativa);
        if ($justificativa === '') {
            throw new InvalidArgumentException("A justificativa é obrigatória para desfazer uma retirada.");
        }

        $itensEstornados = 0;
        $osReaberta      = false;

        $this->pdo->beginTransaction();
        try {
            // ── 1. Travar e validar OS ────────────────────────────────────────
            $stOs = $this->pdo->prepare(
                "SELECT id, status, obs_int
                   FROM ordem_servico
                  WHERE id = ?
                  LIMIT 1 FOR UPDATE"
            );
            $stOs->execute([$osId]);
            $os = $stOs->fetch(PDO::FETCH_ASSOC);

            if (!$os) {
                throw new DomainException("OS #{$osId} não encontrada.");
            }
            if (in_array($os['status'], ['cancelado', 'descartado'], true)) {
                throw new DomainException(
                    "OS #{$osId} está com status global {$os['status']}, impossível desfazer retirada individual."
                );
            }

            // ── 2. Travar e validar equipamento ──────────────────────────────
            $stEq = $this->pdo->prepare(
                "SELECT id, status_equip, nome
                   FROM os_equipamento
                  WHERE os_id = ? AND ordem_idx = ?
                  LIMIT 1 FOR UPDATE"
            );
            $stEq->execute([$osId, $equipIdx]);
            $equip = $stEq->fetch(PDO::FETCH_ASSOC);

            if (!$equip) {
                throw new DomainException("Equipamento #{$equipIdx} não encontrado na OS #{$osId}.");
            }
            if ($equip['status_equip'] !== 'retirado') {
                throw new DomainException(
                    "Equipamento #{$equipIdx} ({$equip['nome']}) não está 'retirado' (status atual: {$equip['status_equip']})."
                );
            }

            // ── 3. Ler orçamento vinculado ao equipamento ─────────────────────
            $stOrc = $this->pdo->prepare(
                "SELECT id, status, total
                   FROM orcamentos
                  WHERE os_id = ? AND equip_idx = ?
                  LIMIT 1 FOR UPDATE"
            );
            $stOrc->execute([$osId, $equipIdx]);
            $orc = $stOrc->fetch(PDO::FETCH_ASSOC) ?: null;

            // ── 4. Marcar equipamento como pronto ───────────────────────────
            $this->pdo->prepare(
                "UPDATE os_equipamento
                    SET status_equip = 'pronto'
                  WHERE os_id = ? AND ordem_idx = ?
                  LIMIT 1"
            )->execute([$osId, $equipIdx]);

            // ── 5. Reverter pagamento no orçamento ────────────────────────
            if ($orc) {
                $this->pdo->prepare(
                    "UPDATE orcamentos SET pago = 0, retirado_por = NULL WHERE id = ? LIMIT 1"
                )->execute([(int) $orc['id']]);
            }

            // ── 6. Estornar estoque dos itens deste equipamento ─────────────────
            $itens = $this->itensRepo->listarComProdutoPorEquipamento($osId, $equipIdx);
            foreach ($itens as $item) {
                $produtoId = (int) $item['produto_id'];
                $qtd       = (float) $item['qtd'];
                if ($produtoId <= 0 || $qtd <= 0) continue;

                $stProd = $this->pdo->prepare(
                    "SELECT estoque_qty, controla_estoque
                       FROM produtos WHERE id = ? LIMIT 1 FOR UPDATE"
                );
                $stProd->execute([$produtoId]);
                $prod = $stProd->fetch(PDO::FETCH_ASSOC);
                if ($prod === false) continue;

                if (!(int) $prod['controla_estoque']) {
                    continue;
                }

                $saldoAnt = (float) $prod['estoque_qty'];
                $saldoPos = $saldoAnt + $qtd;

                $this->pdo->prepare(
                    "UPDATE produtos SET estoque_qty = ? WHERE id = ? LIMIT 1"
                )->execute([$saldoPos, $produtoId]);

                $this->pdo->prepare(
                    "INSERT INTO estoque_movimentacoes
                       (produto_id, os_id, tipo, qtd, saldo_ant, saldo_pos, descricao, usuario_id, criado_em)
                     VALUES (?, ?, 'entrada', ?, ?, ?, ?, ?, NOW())"
                )->execute([
                    $produtoId,
                    $osId,
                    $qtd,
                    $saldoAnt,
                    $saldoPos,
                    "Estorno (Desfazer Retirada) — OS #{$osId} equip. #{$equipIdx}",
                    $operadorId,
                ]);

                $itensEstornados++;
            }

            // ── 6.5 Estornar financeiro do equipamento ────────────────────────
            if ($orc !== null) {
                // Localizar o lançamento específico deste orçamento.
                $stLancEq = $this->pdo->prepare(
                    "SELECT id, status FROM lancamentos_receber WHERE orcamento_id = ? LIMIT 1 FOR UPDATE"
                );
                $stLancEq->execute([(int) $orc['id']]);
                $lancEq = $stLancEq->fetch(PDO::FETCH_ASSOC) ?: null;

                if ($lancEq !== null && in_array($lancEq['status'], ['pago', 'aguardando_fatura'], true)) {
                    $this->pdo->prepare(
                        "UPDATE lancamentos_receber
                            SET status = 'aberto',
                                desconto_valor = 0,
                                valor_pago = 0,
                                data_pagamento = NULL,
                                forma_pagamento = NULL
                          WHERE id = ? LIMIT 1"
                    )->execute([(int) $lancEq['id']]);
                }
            }

            // ── 7. Se a OS estava 'retirado', reabrir para 'pronto' ───────────
            if ($os['status'] === 'retirado') {
                $osReaberta = true;
                $this->pdo->prepare(
                    "UPDATE ordem_servico
                        SET status = 'pronto',
                            data_retirada = NULL,
                            forma_pagamento = NULL,
                            numero_pedido = NULL,
                            operador_retirada_id = NULL
                      WHERE id = ?
                      LIMIT 1"
                )->execute([$osId]);
            }

            // ── 8. Gravar justificativa nas observações internas ───────────
            $novaObs = trim(($os['obs_int'] ?? '') . "\n\n[" . date('d/m/Y H:i') . "] Retirada do equip #{$equipIdx} desfeita pelo operador #{$operadorId}. Motivo: {$justificativa}");
            $this->pdo->prepare(
                "UPDATE ordem_servico SET obs_int = ? WHERE id = ? LIMIT 1"
            )->execute([$novaObs, $osId]);

            $this->pdo->commit();

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        return [
            'os_id'            => $osId,
            'equip_idx'        => $equipIdx,
            'itens_estornados' => $itensEstornados,
            'os_reaberta'      => $osReaberta,
        ];
    }

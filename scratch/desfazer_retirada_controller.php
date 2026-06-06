    /**
     * POST /api/os/{os_id}/equip/{equip_idx}/desfazer-retirada
     * Desfaz a retirada de um equipamento, estornando estoque e financeiro.
     * Requer perfil admin ou recepcao.
     */
    public function desfazerRetiradaEquipamento(Request $request, string $os_id, string $equip_idx): Response
    {
        if (!Auth::temNivel('admin', 'recepcao')) {
            return Response::json(['ok' => false, 'error' => 'Acesso negado.'], 403);
        }

        $justificativa = trim((string) $request->post('justificativa', ''));
        if ($justificativa === '') {
            return Response::json(['ok' => false, 'error' => 'Justificativa é obrigatória.'], 400);
        }

        $usuario    = Auth::user();
        $operadorId = (int) ($usuario['id'] ?? 0);
        $equipIdx   = (int) $equip_idx;

        try {
            $resultado = $this->getService()->desfazerRetiradaEquipamento(
                $os_id,
                $equipIdx,
                $operadorId,
                $justificativa
            );
            return Response::json(array_merge(['ok' => true], $resultado));
        } catch (InvalidArgumentException | DomainException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

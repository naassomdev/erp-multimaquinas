-- ============================================================
-- Migration: 2026_06_06_produto_codigos.sql
-- Etapa: codigos antigos / alternativos / de fornecedor por produto
-- Data: 2026-06-06
-- ============================================================
--
-- Contexto:
--   produtos.codigo e UNIQUE e guarda apenas o codigo ATUAL/principal.
--   Quando um fornecedor muda o codigo de uma peca (ex.: 334668-1 vira um
--   codigo novo), o tecnico que digita o codigo antigo nao encontra o item.
--   Esta tabela normalizada registra os codigos alternativos/antigos,
--   permitindo que a busca resolva o codigo antigo -> produto atual.
--
--   produtos.codigo continua sendo a fonte do codigo principal.
--   Cada linha aqui e um apelido/codigo extra que aponta para o produto atual.
--
-- Dump pre-migration:
--   backups/produto_codigos_20260606_192300/dump_produtos_fornecedores.sql
--
-- Idempotente: CREATE TABLE IF NOT EXISTS.
-- ============================================================

CREATE TABLE IF NOT EXISTS produto_codigos (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    produto_id    INT UNSIGNED NOT NULL,
    codigo        VARCHAR(100) NOT NULL,
    tipo          ENUM('antigo','fornecedor','fabricante','outro') NOT NULL DEFAULT 'antigo',
    fornecedor_id INT UNSIGNED NULL DEFAULT NULL,
    observacao    VARCHAR(255) NOT NULL DEFAULT '',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_produto_codigos_codigo (codigo),
    KEY idx_produto_codigos_produto (produto_id),
    KEY idx_produto_codigos_fornecedor (fornecedor_id),

    CONSTRAINT fk_produto_codigos_produto
        FOREIGN KEY (produto_id) REFERENCES produtos (id) ON DELETE CASCADE,
    CONSTRAINT fk_produto_codigos_fornecedor
        FOREIGN KEY (fornecedor_id) REFERENCES fornecedores (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nota: a unicidade CRUZADA (um codigo alternativo nao pode colidir com um
-- produtos.codigo ja existente, nem com outro alternativo) e garantida na
-- camada de aplicacao (ProdutoRepository), pois envolve duas tabelas.
-- A UNIQUE acima garante apenas que o mesmo codigo nao se repita dentro
-- de produto_codigos.

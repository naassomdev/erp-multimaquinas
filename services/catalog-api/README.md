# Catalog API — microserviço de vista explodida

Microserviço Node.js que faz scraping de 4 fontes de **vistas explodidas e peças
de reposição** e expõe os resultados via API REST. É consumido **somente pelo
PHP** do ERP (proxy via `App\Services\CatalogService`), nunca diretamente pelo
navegador.

| Fonte       | Origem                                         | Região |
|-------------|------------------------------------------------|--------|
| `felap`     | ferramentasfelap.com.br                        | 🇧🇷 BR  |
| `tsn`       | toolservicenet.com (Stanley B&D)               | 🇺🇸 US  |
| `bosch`     | boschtoolservice.com/br/pt                     | 🇧🇷 BR  |
| `milwaukee` | milwaukeetool.com/support/manuals-and-downloads| 🇺🇸 US  |

## Endpoints

| Método | Rota                                                   | Descrição                       |
|--------|--------------------------------------------------------|----------------------------------|
| GET    | `/api/fontes`                                          | Lista as 4 fontes                |
| GET    | `/api/marcas[?fonte=...]`                              | Marcas suportadas por fonte      |
| GET    | `/api/modelos?fonte=felap&marca=2[&q=...]`             | Modelos Felap por marca          |
| GET    | `/api/modelos?fonte=tsn&brand=DW[&q=...]`              | Modelos TSN por brand            |
| GET    | `/api/produto?fonte=bosch&modelo=GSB+13+RE`            | Produto Bosch por nome           |
| GET    | `/api/produto?fonte=bosch&typenr=060113C518`           | Produto Bosch por nº de tipo     |
| GET    | `/api/produto?fonte=milwaukee&modelo=2804-20`          | Manuais + PDFs Milwaukee         |
| GET    | `/api/produto?fonte=tsn&modelo=DCD996B_1`              | Produto TSN com vistas           |
| GET    | `/api/pdf?marca=2&modelo=DCD776-TIPO10.pdf`            | Redirect 302 para PDF Felap      |
| DELETE | `/api/cache`                                           | Limpa o cache                    |
| GET    | `/health`                                              | Healthcheck (`{ok:true}`)        |

## IDs Felap

| ID | Marca           | ID | Marca            |
|----|-----------------|----|------------------|
| 1  | Bosch           | 6  | Skil             |
| 2  | DeWalt          | 7  | Dremel           |
| 3  | Makita          | 8  | Milwaukee        |
| 4  | Metabo          | 9  | Hitachi/HiKOKI   |
| 5  | Black & Decker  | 10 | Ryobi            |

## Cache

| Fonte                            | TTL    |
|----------------------------------|--------|
| Felap (modelos por marca)        | 1 hora |
| TSN / Bosch / Milwaukee          | 4 horas|

## Deploy na VPS (aaPanel + PM2)

> Pré-requisito: **Node.js 18+** instalado no aaPanel (App Store → Node.js).

```bash
cd /www/wwwroot/multimaquinas.site/services/catalog-api
npm install --omit=dev
pm2 start ecosystem.config.cjs
pm2 save
pm2 startup        # autoinicialização no boot (segue instruções que aparecem)
```

Para verificar:

```bash
curl http://127.0.0.1:3001/health
# => {"ok":true,"ts":1709...}

pm2 logs catalog-api --lines 50
pm2 status
```

### Atualizando o serviço

```bash
cd /www/wwwroot/multimaquinas.site/services/catalog-api
git pull            # ou rsync da nova versão
npm install --omit=dev
pm2 reload catalog-api
```

## Importante — segurança

O `ecosystem.config.cjs` força `HOST=127.0.0.1`, ou seja, a API só é
acessível localmente na VPS. **Não exponha** a porta 3001 no firewall do
servidor: o painel do técnico fala com ela através do PHP
(`App\Services\CatalogService`), que por sua vez está atrás do `AuthMiddleware`.

Se em algum momento for necessário expor (debug remoto), use SSH tunnel:
```bash
ssh -L 3001:127.0.0.1:3001 root@vps
# agora http://localhost:3001 da máquina local funciona
```

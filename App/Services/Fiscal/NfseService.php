<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Core\Env;
use App\Services\NfseSettingsService;
use Nfse\Dto\Http\Endpoint;
use Nfse\Dto\Nfse\CancelamentoData;
use RuntimeException;
use Throwable;

use Nfse\Dto\Nfse\DpsData;
use Nfse\Dto\Nfse\PedRegEventoData;
use Nfse\Enums\TipoAmbiente;
use Nfse\Http\Client\AdnClient;
use Nfse\Http\Client\SefinClient;
use Nfse\Http\Exceptions\NfseApiException;
use Nfse\Http\NfseContext;
use Nfse\Support\IdGenerator;
use Nfse\Support\SefinEndpointResolver;
use Nfse\Xml\DpsXmlBuilder;
use Nfse\Xml\NfseXmlParser;

/**
 * Camada de integração do app principal com o SDK nacional.
 */
final class NfseService
{
    private static ?array $settingsCache = null;

    public function __construct(private readonly ?CertificateManager $certManager = null) {}

    public static function supportsRealEmission(): bool
    {
        return self::sdkAvailable()
            && self::setting('enabled', '0') === '1'
            && self::setting('write_enabled', '0') === '1'
            && in_array(self::setting('ambiente', Env::get('NFSE_AMBIENTE', 'homologacao') ?? 'homologacao'), ['homologacao', 'producao'], true)
            && filter_var((string)(self::setting('real_enabled', Env::get('NFSE_REAL_ENABLED', '0') ?? '0') ?: '0'), FILTER_VALIDATE_BOOLEAN);
    }

    public static function integrationMode(): string
    {
        return self::supportsRealEmission() ? 'real' : 'simulacao';
    }

    public static function normalizeEndpoint(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = rtrim($value, '/');
        $value = preg_replace('#/docs/index(?:\.html)?$#i', '', $value) ?? $value;
        $value = preg_replace('#/docs$#i', '', $value) ?? $value;

        return rtrim($value, '/');
    }

    public static function endpointLooksOfficial(string $value, string $ambiente): bool
    {
        $value = self::normalizeEndpoint($value);
        if ($value === '') {
            return true;
        }

        $parts = parse_url($value);
        $host = strtolower((string)($parts['host'] ?? ''));
        $path = strtolower((string)($parts['path'] ?? ''));
        $expectedHost = strtolower(
            $ambiente === 'producao'
                ? 'sefin.nfse.gov.br'
                : 'sefin.producaorestrita.nfse.gov.br'
        );

        if ($host !== $expectedHost) {
            return false;
        }

        return str_contains($path, '/sefinnacional')
            || str_contains($path, '/api/sefinnacional');
    }

    public static function officialEndpoint(string $ambiente): string
    {
        return $ambiente === 'producao'
            ? 'https://sefin.nfse.gov.br/SefinNacional'
            : 'https://sefin.producaorestrita.nfse.gov.br/SefinNacional';
    }

    /**
     * Emite uma NFS-e a partir do payload da OS.
     *
     * @param array{
     *   nota_id:int, os_id:string|int, nome_cliente:string, cpf_cnpj:string,
     *   valor_servico:float, descricao:string, ambiente?:string,
     *   codigo_servico?:string, aliquota_iss?:float
     * } $dados
     *
     * @return array{status:string, numero:?string, protocolo:?string, xml:?string, chave_acesso:?string, id_dps:?string, simulado?:bool}
     */
    public function emitir(array $dados): array
    {
        try {
            if (self::supportsRealEmission()) {
                return $this->emitirReal($dados);
            }

            return $this->_simular($dados);

        } catch (\Throwable $e) {
            throw new RuntimeException('Falha na emissão NFS-e: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Cancela NFS-e autorizada. Em modo simulação só devolve sucesso.
     */
    public function cancelar(string $numero, string $motivo): array
    {
        if (self::supportsRealEmission()) {
            return $this->cancelarReal($numero, $motivo);
        }

        return [
            'status'    => 'cancelada',
            'numero'    => $numero,
            'motivo'    => $motivo,
            'simulado'  => true,
        ];
    }

    /**
     * Consulta a NFS-e autorizada pela chave de acesso e devolve o XML oficial.
     *
     * @return array{chave_acesso:string, numero:?string, id_dps:?string, xml:string}|null
     */
    public function consultarNfse(string $chaveAcesso): ?array
    {
        if (!self::supportsRealEmission()) {
            return null;
        }

        self::bootSdk();

        try {
            $response = $this->sefinClient()->consultarNfse($this->digitsOnly($chaveAcesso));
            $nfseXml = $this->decodeCompressedXml(
                $response->nfseXmlGZipB64 ?? null,
                'Resposta da API sem XML da NFS-e.'
            );
            $nfseData = $this->parseNfseXml($nfseXml);

            return [
                'chave_acesso' => (string)($response->chaveAcesso ?? $this->digitsOnly($chaveAcesso)),
                'numero' => $nfseData->infNfse?->numeroNfse,
                'id_dps' => $nfseData->infNfse?->dps?->infDps?->id,
                'xml' => $nfseXml,
            ];
        } catch (NfseApiException $e) {
            return null;
        }
    }

    /**
     * @return array{id_dps:string, chave_acesso:?string}|null
     */
    public function consultarDps(string $idDps): ?array
    {
        if (!self::supportsRealEmission()) {
            return null;
        }

        self::bootSdk();

        try {
            $response = $this->sefinClient()->consultarDps($idDps);
            return [
                'id_dps' => (string)($response->idDps ?? $idDps),
                'chave_acesso' => $response->chaveAcesso ?: null,
            ];
        } catch (NfseApiException $e) {
            return null;
        }
    }

    public function verificarDps(string $idDps): bool
    {
        if (!self::supportsRealEmission()) {
            return false;
        }

        self::bootSdk();

        try {
            return $this->sefinClient()->verificarDps($idDps);
        } catch (NfseApiException $e) {
            return false;
        }
    }

    public function baixarDanfse(string $chaveAcesso): string
    {
        if (!self::supportsRealEmission()) {
            throw new RuntimeException('Modo real desativado para a integração NFS-e.');
        }

        self::bootSdk();

        try {
            return $this->adnClient()->obterDanfse($this->digitsOnly($chaveAcesso));
        } catch (NfseApiException $e) {
            throw new RuntimeException('Falha ao baixar o DANFSE: ' . $this->formatSdkException($e), 0, $e);
        }
    }

    /**
     * Gera o ID oficial da DPS para uma nota local.
     */
    public function gerarIdDps(int $notaId): string
    {
        self::bootSdk();

        $cfg = $this->prestadorConfig();
        $numeroDps = str_pad((string)$notaId, 15, '0', STR_PAD_LEFT);
        $serie = (string)(self::setting('serie_dps', Env::get('NFSE_SERIE_DPS', '1') ?? '1') ?: '1');

        return IdGenerator::generateDpsId($cfg['cnpj'], $cfg['codigo_municipio'], $serie, $numeroDps);
    }

    /**
     * Extrai metadados úteis do XML da NFS-e já retornado pela SEFIN.
     *
     * @return array{numero:?string,id_dps:?string}
     */
    public function extrairMetadadosXml(string $xml): array
    {
        if (trim($xml) === '') {
            return ['numero' => null, 'id_dps' => null];
        }

        try {
            self::bootSdk();
            $nfseData = $this->parseNfseXml($xml);

            return [
                'numero' => $nfseData->infNfse?->numeroNfse,
                'id_dps' => $nfseData->infNfse?->dps?->infDps?->id,
            ];
        } catch (Throwable) {
            return ['numero' => null, 'id_dps' => null];
        }
    }

    /**
     * Consulta os parâmetros municipais oficiais usados pela emissão nacional.
     *
     * @return array{
     *   ok:bool,
     *   codigo_municipio:string,
     *   codigo_servico:string,
     *   competencia:string,
     *   convenio:?array<string,mixed>,
     *   aliquota:?array<string,mixed>,
     *   retencao:?array<string,mixed>,
     *   checks:list<array{label:string,ok:bool,detalhe:string}>,
     *   alertas:list<string>,
     *   erros:list<string>
     * }
     */
    public function diagnosticarParametrosMunicipais(?string $codigoServico = null, ?string $competencia = null): array
    {
        self::bootSdk();

        $ident = $this->prestadorIdentificacao();
        $codigoMunicipio = $ident['codigo_municipio'];
        $codigoServico = $this->digitsOnly((string)($codigoServico ?: self::setting('codigo_trib_nacional', Env::get('NFSE_CODIGO_TRIB_NACIONAL', '') ?? '')));
        $competencia = trim((string)($competencia ?: date('Y-m-d')));

        $result = [
            'ok' => false,
            'codigo_municipio' => $codigoMunicipio,
            'codigo_servico' => $codigoServico,
            'competencia' => $competencia,
            'convenio' => null,
            'aliquota' => null,
            'retencao' => null,
            'checks' => [],
            'alertas' => [],
            'erros' => [],
        ];

        try {
            $convenioResponse = $this->contribuinteService()->consultarParametrosConvenio($codigoMunicipio);
            $convenio = $convenioResponse->parametrosConvenio;

            $result['convenio'] = [
                'mensagem' => $convenioResponse->mensagem,
                'aderente_ambiente_nacional' => $convenio?->aderenteAmbienteNacional,
                'aderente_emissor_nacional' => $convenio?->aderenteEmissorNacional,
                'situacao_emissao_padrao_rfb' => $convenio?->situacaoEmissaoPadraoContribuintesRFB,
                'aderente_man' => $convenio?->aderenteMAN,
                'permite_aproveitamento_creditos' => $convenio?->permiteAproveitametoDeCreditos,
            ];

            $result['checks'][] = [
                'label' => 'Convênio municipal consultado',
                'ok' => true,
                'detalhe' => $convenioResponse->mensagem ?: 'Consulta concluída na API de Parâmetros Municipais.',
            ];
            $result['checks'][] = [
                'label' => 'Município aderente ao Ambiente Nacional',
                'ok' => $convenio?->aderenteAmbienteNacional === 1,
                'detalhe' => 'Valor retornado: ' . (string)($convenio?->aderenteAmbienteNacional ?? 'não informado'),
            ];
            $result['checks'][] = [
                'label' => 'Município aderente ao Emissor Nacional',
                'ok' => $convenio?->aderenteEmissorNacional === 1,
                'detalhe' => 'Valor retornado: ' . (string)($convenio?->aderenteEmissorNacional ?? 'não informado'),
            ];

            if ($codigoServico === '') {
                array_unshift($result['checks'], [
                    'label' => 'API de Parâmetros Municipais acessível',
                    'ok' => true,
                    'detalhe' => 'Convênio consultado com sucesso. Defina o código de serviço para validar alíquota e retenção.',
                ]);
                $result['checks'][] = [
                    'label' => 'Código de serviço configurado',
                    'ok' => false,
                    'detalhe' => 'Defina o código de tributação nacional para consultar alíquota, retenção e regimes.',
                ];
                $result['alertas'][] = 'Código de tributação nacional não configurado.';
                $result['ok'] = false;

                return $result;
            }

            $aliquotaResponse = $this->contribuinteService()->consultarAliquota($codigoMunicipio, $codigoServico, $competencia);
            $aliquota = $this->selecionarAliquotaVigente($aliquotaResponse->aliquotas[$codigoServico] ?? [], $competencia);
            $result['aliquota'] = $aliquota !== null ? [
                'incidencia' => $aliquota->incidencia,
                'aliquota' => $aliquota->aliquota,
                'data_inicio' => $aliquota->dataInicio,
                'data_fim' => $aliquota->dataFim,
                'mensagem' => $aliquotaResponse->mensagem,
            ] : null;

            $result['checks'][] = [
                'label' => 'Alíquota vigente localizada',
                'ok' => $aliquota !== null && $aliquota->aliquota !== null,
                'detalhe' => $aliquota !== null && $aliquota->aliquota !== null
                    ? 'Alíquota oficial: ' . number_format((float)$aliquota->aliquota, 2, '.', '') . '%.'
                    : ($aliquotaResponse->mensagem ?: 'A API não devolveu alíquota vigente para o código de serviço.'),
            ];

            $retencoes = $this->contribuinteService()->consultarRetencoes($codigoMunicipio, $competencia);
            $retencao = $this->avaliarRetencoesMunicipais($retencoes, $codigoServico, $competencia);
            $result['retencao'] = $retencao;
            $result['checks'][] = [
                'label' => 'Retenções municipais consultadas',
                'ok' => true,
                'detalhe' => $retencao['detalhe'],
            ];

            $regimes = $this->contribuinteService()->consultarRegimesEspeciais($codigoMunicipio, $codigoServico, $competencia);
            $regimeCount = $this->contarRegimesEspeciais($regimes);
            $result['checks'][] = [
                'label' => 'Regimes especiais consultados',
                'ok' => true,
                'detalhe' => $regimeCount > 0
                    ? "{$regimeCount} configuração(ões) retornadas."
                    : 'Nenhuma configuração especial retornada para o serviço/competência.',
            ];

            if (($retencao['municipal_ativa'] ?? false) === true) {
                $result['alertas'][] = 'Há parametrização municipal de retenção ativa para este serviço. Revise o tipo de retenção do ISSQN antes de emitir.';
            }

            if ($aliquota === null || $aliquota->aliquota === null) {
                $result['alertas'][] = 'A API oficial não devolveu alíquota vigente para o serviço informado.';
            }

            $result['ok'] = $result['erros'] === [];
        } catch (NfseApiException $e) {
            $result['erros'][] = $this->formatSdkException($e);
        } catch (Throwable $e) {
            $result['erros'][] = $e->getMessage();
        }

        if ($result['erros'] !== []) {
            $result['checks'][] = [
                'label' => 'API de Parâmetros Municipais acessível',
                'ok' => false,
                'detalhe' => implode(' | ', $result['erros']),
            ];
        } else {
            array_unshift($result['checks'], [
                'label' => 'API de Parâmetros Municipais acessível',
                'ok' => true,
                'detalhe' => 'Consultas oficiais concluídas com certificado do contribuinte.',
            ]);
        }

        return $result;
    }

    private function _simular(array $dados): array
    {
        return [
            'status'    => 'autorizada',
            'numero'    => str_pad((string)$dados['nota_id'], 6, '0', STR_PAD_LEFT),
            'protocolo' => 'SIM-' . date('YmdHis') . '-' . $dados['nota_id'],
            'xml'       => null,
            'simulado'  => true,
        ];
    }

    private function emitirReal(array $dados): array
    {
        self::bootSdk();

        $pem = $this->certManager?->exportPem() ?? throw new RuntimeException('Certificado obrigatório para emissão real.');
        $cfg = $this->prestadorConfig();
        $tomador = $this->tomadorConfig($dados);
        $ambiente = $this->tipoAmbiente((string)($dados['ambiente'] ?? 'homologacao'));
        $parametros = $this->resolverParametrosEmissao($dados, $cfg);
        $numeroDps = str_pad((string)$dados['nota_id'], 15, '0', STR_PAD_LEFT);
        $serie = (string)(self::setting('serie_dps', Env::get('NFSE_SERIE_DPS', '1') ?? '1') ?: '1');
        $idDps = IdGenerator::generateDpsId($cfg['cnpj'], $cfg['codigo_municipio'], $serie, $numeroDps);

        $dps = new DpsData([
            '@attributes' => ['versao' => '1.00'],
            'infDPS' => [
                '@attributes' => ['Id' => $idDps],
                'tpAmb' => $ambiente === TipoAmbiente::Producao ? 1 : 2,
                'dhEmi' => date('c'),
                'verAplic' => 'ERP-Multimaquinas',
                'serie' => $serie,
                'nDPS' => $numeroDps,
                'dCompet' => date('Y-m-d'),
                'tpEmit' => 1,
                'cLocEmi' => $cfg['codigo_municipio'],
                'prest' => [
                    'CNPJ' => $cfg['cnpj'],
                    'IM' => $cfg['inscricao_municipal'],
                    'xNome' => $cfg['razao_social'],
                    'end' => [
                        'endNac' => [
                            'cMun' => $cfg['codigo_municipio'],
                            'CEP' => $cfg['cep'],
                        ],
                        'xLgr' => $cfg['logradouro'],
                        'nro' => $cfg['numero'],
                        'xCpl' => $cfg['complemento'],
                        'xBairro' => $cfg['bairro'],
                    ],
                    'fone' => $cfg['telefone'],
                    'email' => $cfg['email'],
                    'regTrib' => [
                        'opSimpNac' => $cfg['opcao_simples'],
                        'regApTribSN' => $cfg['regime_apuracao_sn'],
                        'regEspTrib' => $cfg['regime_especial'],
                    ],
                ],
                'toma' => $tomador,
                'serv' => [
                    'locPrest' => [
                        'cLocPrestacao' => $parametros['codigo_municipio'],
                    ],
                    'cServ' => [
                        'cTribNac' => (string)($dados['codigo_servico'] ?? self::setting('codigo_trib_nacional', Env::get('NFSE_CODIGO_TRIB_NACIONAL', '') ?? '') ?: ''),
                        'cTribMun' => (string)(self::setting('codigo_trib_municipal', Env::get('NFSE_CODIGO_TRIB_MUNICIPAL', '') ?? '') ?: ''),
                        'xDescServ' => (string)($dados['descricao'] ?: self::setting('descricao_servico_padrao', Env::get('NFSE_DESCRICAO_SERVICO_PADRAO', 'Serviço prestado') ?? 'Serviço prestado') ?: 'Serviço prestado'),
                    ],
                    'infoCompl' => [
                        'xInfComp' => (string)($dados['descricao'] ?? ''),
                    ],
                ],
                'valores' => [
                    'vServPrest' => [
                        'vServ' => round((float)$dados['valor_servico'], 2),
                    ],
                    'trib' => [
                        'tribMun' => array_filter([
                            'tribISSQN' => $parametros['tributacao_issqn'],
                            'tpRetISSQN' => $parametros['tipo_retencao_issqn'],
                            'pAliq' => $parametros['aliquota'],
                        ], static fn ($value): bool => $value !== null && $value !== ''),
                        'tribFed' => [
                            'piscofins' => [
                                'CST' => (string)(self::setting('piscofins_cst', Env::get('NFSE_PISCOFINS_CST', '08') ?? '08') ?: '08'),
                            ],
                        ],
                        'totTrib' => [
                            'indTotTrib' => 0,
                        ],
                    ],
                ],
            ],
        ]);

        $builder = new DpsXmlBuilder();
        $xml = $builder->build($dps);

        $signer = new PemXmlSigner($pem['cert'], $pem['pkey']);
        $signedXml = $signer->sign($xml, 'infDPS');
        $payload = base64_encode(gzencode($signedXml));

        $response = $this->postJsonWithPem($this->resolveEndpoint($ambiente, $cfg['codigo_municipio']) . '/nfse', [
            'dpsXmlGZipB64' => $payload,
        ], $pem['cert'], $pem['pkey']);

        if (!empty($response['erros']) && is_array($response['erros'])) {
            throw new RuntimeException('Erro na emissão NFS-e: ' . $this->formatApiMessages($response['erros']));
        }

        if (empty($response['nfseXmlGZipB64'])) {
            throw new RuntimeException('Resposta da API sem XML da NFS-e.');
        }

        $nfseXml = gzdecode((string)base64_decode((string)$response['nfseXmlGZipB64'], true));
        if (!is_string($nfseXml) || $nfseXml === '') {
            throw new RuntimeException('Falha ao descompactar o XML retornado pela API.');
        }

        $parser = new NfseXmlParser();
        $nfseData = $parser->parse($nfseXml);

        return [
            'status' => 'autorizada',
            'numero' => $nfseData->infNfse?->numeroNfse,
            'protocolo' => $response['chaveAcesso'] ?? $response['idDps'] ?? null,
            'xml' => $nfseXml,
            'chave_acesso' => $response['chaveAcesso'] ?? null,
            'id_dps' => $nfseData->infNfse?->dps?->infDps?->id ?? ($response['idDps'] ?? null),
            'alertas' => $parametros['alertas'],
            'simulado' => false,
        ];
    }

    private function cancelarReal(string $chaveAcesso, string $motivo): array
    {
        if (trim($chaveAcesso) === '') {
            throw new RuntimeException('Chave de acesso da NFS-e é obrigatória para cancelamento oficial.');
        }

        self::bootSdk();

        $cfg = $this->prestadorIdentificacao();
        $ambiente = $this->tipoAmbiente((string)(Env::get('NFSE_AMBIENTE', 'homologacao') ?: 'homologacao'));

        $evento = new PedRegEventoData([
            'versao' => '1.01',
            'infPedReg' => [
                'tpAmb' => $ambiente === TipoAmbiente::Producao ? 1 : 2,
                'verAplic' => 'ERP-Multimaquinas',
                'dhEvento' => date('c'),
                'chNFSe' => $this->digitsOnly($chaveAcesso),
                'cnpjAutor' => $cfg['cnpj'],
                'tipoEvento' => '101101',
                'e101101' => new CancelamentoData([
                    'xDesc' => 'Cancelamento de NFS-e',
                    'cMotivo' => '1',
                    'xMotivo' => $motivo,
                ]),
            ],
        ]);

        try {
            $response = $this->contribuinteService()->cancelar($evento);
            $xmlEvento = $response->eventoXmlGZipB64
                ? $this->decodeCompressedXml($response->eventoXmlGZipB64, 'Resposta da API sem XML do evento de cancelamento.')
                : null;

            return [
                'status' => 'cancelada',
                'numero' => null,
                'motivo' => $motivo,
                'chave_acesso' => $this->digitsOnly($chaveAcesso),
                'xml_evento' => $xmlEvento,
                'simulado' => false,
            ];
        } catch (NfseApiException $e) {
            throw new RuntimeException('Falha no cancelamento da NFS-e: ' . $this->formatSdkException($e), 0, $e);
        }
    }

    private function prestadorConfig(): array
    {
        $config = [
            'cnpj' => preg_replace('/\D/', '', (string)(self::setting('prestador_cnpj', Env::get('NFSE_PRESTADOR_CNPJ', '') ?? '') ?: '')) ?: '',
            'razao_social' => trim((string)(self::setting('prestador_razao_social', Env::get('NFSE_PRESTADOR_RAZAO_SOCIAL', '') ?? '') ?: '')),
            'inscricao_municipal' => trim((string)(self::setting('prestador_inscricao_municipal', Env::get('NFSE_PRESTADOR_INSCRICAO_MUNICIPAL', '') ?? '') ?: '')),
            'codigo_municipio' => preg_replace('/\D/', '', (string)(self::setting('prestador_codigo_municipio', Env::get('NFSE_PRESTADOR_CODIGO_MUNICIPIO', '') ?? '') ?: '')) ?: '',
            'cep' => preg_replace('/\D/', '', (string)(self::setting('prestador_cep', Env::get('NFSE_PRESTADOR_CEP', '') ?? '') ?: '')) ?: '',
            'logradouro' => trim((string)(self::setting('prestador_logradouro', Env::get('NFSE_PRESTADOR_LOGRADOURO', '') ?? '') ?: '')),
            'numero' => trim((string)(self::setting('prestador_numero', Env::get('NFSE_PRESTADOR_NUMERO', '') ?? '') ?: '')),
            'complemento' => trim((string)(self::setting('prestador_complemento', Env::get('NFSE_PRESTADOR_COMPLEMENTO', '') ?? '') ?: '')),
            'bairro' => trim((string)(self::setting('prestador_bairro', Env::get('NFSE_PRESTADOR_BAIRRO', '') ?? '') ?: '')),
            'telefone' => preg_replace('/\D/', '', (string)(self::setting('prestador_telefone', Env::get('NFSE_PRESTADOR_TELEFONE', '') ?? '') ?: '')) ?: '',
            'email' => trim((string)(self::setting('prestador_email', Env::get('NFSE_PRESTADOR_EMAIL', '') ?? '') ?: '')),
            'opcao_simples' => (int)(self::setting('prestador_opcao_simples', Env::get('NFSE_PRESTADOR_OPCAO_SIMPLES', '1') ?? '1') ?: 1),
            'regime_apuracao_sn' => ($v = trim((string)(self::setting('prestador_regime_apuracao_sn', Env::get('NFSE_PRESTADOR_REGIME_APURACAO_SN', '') ?? '') ?: ''))) === '' ? null : (int)$v,
            'regime_especial' => (int)(self::setting('prestador_regime_especial', Env::get('NFSE_PRESTADOR_REGIME_ESPECIAL', '0') ?? '0') ?: 0),
        ];

        $faltando = [];
        foreach ([
            'cnpj' => 'NFSE_PRESTADOR_CNPJ',
            'razao_social' => 'NFSE_PRESTADOR_RAZAO_SOCIAL',
            'inscricao_municipal' => 'NFSE_PRESTADOR_INSCRICAO_MUNICIPAL',
            'codigo_municipio' => 'NFSE_PRESTADOR_CODIGO_MUNICIPIO',
            'cep' => 'NFSE_PRESTADOR_CEP',
            'logradouro' => 'NFSE_PRESTADOR_LOGRADOURO',
            'numero' => 'NFSE_PRESTADOR_NUMERO',
            'bairro' => 'NFSE_PRESTADOR_BAIRRO',
            'email' => 'NFSE_PRESTADOR_EMAIL',
            'telefone' => 'NFSE_PRESTADOR_TELEFONE',
        ] as $key => $envKey) {
            if ($config[$key] === '' || $config[$key] === null) {
                $faltando[] = $envKey;
            }
        }

        if ((string)(self::setting('codigo_trib_nacional', Env::get('NFSE_CODIGO_TRIB_NACIONAL', '') ?? '') ?: '') === '') {
            $faltando[] = 'NFSE_CODIGO_TRIB_NACIONAL';
        }

        if ($faltando !== []) {
            throw new RuntimeException('Configuração fiscal incompleta para NFS-e. Preencha: ' . implode(', ', $faltando));
        }

        return $config;
    }

    private function tomadorConfig(array $dados): array
    {
        $doc = preg_replace('/\D/', '', (string)($dados['cpf_cnpj'] ?? '')) ?: '';
        $isCnpj = strlen($doc) === 14;
        $isCpf = strlen($doc) === 11;

        $tomador = [
            'xNome' => (string)($dados['nome_cliente'] ?? 'Consumidor não identificado'),
            'fone' => preg_replace('/\D/', '', (string)($dados['cliente_telefone'] ?? '')) ?: '',
            'email' => trim((string)($dados['cliente_email'] ?? '')),
        ];

        if ($isCnpj) {
            $tomador['CNPJ'] = $doc;
        } elseif ($isCpf) {
            $tomador['CPF'] = $doc;
        }

        if ($doc !== '') {
            foreach ([
                'cliente_cod_cidade' => 'código IBGE do tomador',
                'cliente_cep' => 'CEP do tomador',
                'cliente_endereco' => 'logradouro do tomador',
                'cliente_numero' => 'número do tomador',
                'cliente_bairro' => 'bairro do tomador',
            ] as $field => $label) {
                if (trim((string)($dados[$field] ?? '')) === '') {
                    throw new RuntimeException("Tomador identificado exige {$label}. Atualize o cadastro do cliente antes de emitir a NFS-e.");
                }
            }

            $tomador['end'] = [
                'endNac' => [
                    'cMun' => preg_replace('/\D/', '', (string)$dados['cliente_cod_cidade']) ?: '',
                    'CEP' => preg_replace('/\D/', '', (string)$dados['cliente_cep']) ?: '',
                ],
                'xLgr' => (string)$dados['cliente_endereco'],
                'nro' => (string)$dados['cliente_numero'],
                'xCpl' => (string)($dados['cliente_complemento'] ?? ''),
                'xBairro' => (string)$dados['cliente_bairro'],
            ];
        }

        return $tomador;
    }

    private function tipoAmbiente(string $ambiente): TipoAmbiente
    {
        return strtolower($ambiente) === 'producao'
            ? TipoAmbiente::Producao
            : TipoAmbiente::Homologacao;
    }

    private function resolveEndpoint(TipoAmbiente $ambiente, string $codigoMunicipio): string
    {
        $custom = $this->configuredEndpoint($ambiente);
        if ($custom !== '') {
            return $custom;
        }

        $resolver = new SefinEndpointResolver();
        return rtrim($resolver->resolve(new NfseContext(
            ambiente: $ambiente,
            certificatePath: '',
            certificatePassword: '',
            codigoMunicipio: $codigoMunicipio,
        )), '/');
    }

    private function prestadorIdentificacao(): array
    {
        $cnpj = $this->digitsOnly((string)(self::setting('prestador_cnpj', Env::get('NFSE_PRESTADOR_CNPJ', '') ?? '') ?: ''));
        if ($cnpj === '') {
            throw new RuntimeException('Configuração fiscal incompleta para NFS-e. Preencha NFSE_PRESTADOR_CNPJ.');
        }

        return [
            'cnpj' => $cnpj,
            'codigo_municipio' => $this->digitsOnly((string)(self::setting('prestador_codigo_municipio', Env::get('NFSE_PRESTADOR_CODIGO_MUNICIPIO', '') ?? '') ?: '')),
        ];
    }

    private function postJsonWithPem(string $url, array $data, string $certPem, string $keyPem): array
    {
        $certFile = tempnam(sys_get_temp_dir(), 'nfse-cert-');
        $keyFile = tempnam(sys_get_temp_dir(), 'nfse-key-');
        if ($certFile === false || $keyFile === false) {
            throw new RuntimeException('Falha ao criar arquivos temporários para autenticação TLS.');
        }

        file_put_contents($certFile, $certPem);
        file_put_contents($keyFile, $keyPem);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_SSLCERT => $certFile,
            CURLOPT_SSLKEY => $keyFile,
            CURLOPT_SSLCERTTYPE => 'PEM',
            CURLOPT_SSLKEYTYPE => 'PEM',
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
        ]);

        try {
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
        } finally {
            curl_close($ch);
            @unlink($certFile);
            @unlink($keyFile);
        }

        if ($body === false) {
            throw new RuntimeException('Falha de comunicação com a API NFS-e: ' . $err);
        }

        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Resposta inválida da API NFS-e (HTTP {$code}).");
        }

        if ($code >= 400) {
            $erro = !empty($decoded['erros']) && is_array($decoded['erros'])
                ? $this->formatApiMessages($decoded['erros'])
                : ('HTTP ' . $code);
            throw new RuntimeException('API NFS-e rejeitou a requisição: ' . $erro);
        }

        return $decoded;
    }

    private function formatApiMessages(array $messages): string
    {
        $parts = [];
        foreach ($messages as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $parts[] = trim(implode(' ', array_filter([
                $msg['codigo'] ?? $msg['Codigo'] ?? null,
                $msg['mensagem'] ?? $msg['Mensagem'] ?? $msg['descricao'] ?? $msg['Descricao'] ?? null,
                $msg['complemento'] ?? $msg['Complemento'] ?? null,
            ], static fn ($v) => $v !== null && $v !== '')));
        }

        return implode(' | ', array_filter($parts)) ?: 'Erro não detalhado pela API';
    }

    private function contribuinteService(): \Nfse\Service\ContribuinteService
    {
        return (new \Nfse\Nfse($this->sdkContext()))->contribuinte();
    }

    private function sefinClient(): SefinClient
    {
        return new SefinClient($this->sdkContext());
    }

    private function adnClient(): AdnClient
    {
        return new AdnClient($this->sdkContext());
    }

    private function sdkContext(): NfseContext
    {
        $certPath = trim((string)(self::setting('cert_path', Env::get('CERT_PATH', '') ?? '') ?: ''));
        $certPass = (string)(self::setting('cert_password', Env::get('CERT_PASSWORD', '') ?? '') ?: '');
        if ($certPath === '') {
            throw new RuntimeException('Certificado digital não configurado para a integração NFS-e.');
        }

        $ident = $this->prestadorIdentificacao();
        $ambiente = $this->tipoAmbiente((string)(Env::get('NFSE_AMBIENTE', 'homologacao') ?: 'homologacao'));
        $customEndpoint = $this->configuredEndpoint($ambiente);

        return new NfseContext(
            ambiente: $ambiente,
            certificatePath: $certPath,
            certificatePassword: $certPass,
            codigoMunicipio: $ident['codigo_municipio'] !== '' ? $ident['codigo_municipio'] : null,
            endpoint: $customEndpoint !== ''
                ? new Endpoint([
                    'production' => $customEndpoint,
                    'homologation' => $customEndpoint,
                ])
                : null,
        );
    }

    private function decodeCompressedXml(?string $payload, string $emptyMessage): string
    {
        if (!$payload) {
            throw new RuntimeException($emptyMessage);
        }

        $decoded = base64_decode($payload, true);
        $xml = is_string($decoded) ? gzdecode($decoded) : false;
        if (!is_string($xml) || trim($xml) === '') {
            throw new RuntimeException('Falha ao descompactar o XML retornado pela API.');
        }

        return $xml;
    }

    private function parseNfseXml(string $xml): \Nfse\Dto\Nfse\NfseData
    {
        $parser = new NfseXmlParser();
        return $parser->parse($xml);
    }

    private function formatSdkException(NfseApiException $e): string
    {
        $parts = [];
        foreach ($e->getErrors() as $error) {
            $parts[] = trim(implode(' ', array_filter([
                $error->codigo ?? null,
                $error->mensagem ?? $error->descricao ?? null,
                $error->complemento ?? null,
            ], static fn ($v) => $v !== null && $v !== '')));
        }

        if ($parts !== []) {
            return implode(' | ', $parts);
        }

        return $e->getMessage();
    }

    private function digitsOnly(string $value): string
    {
        return preg_replace('/\D/', '', $value) ?: '';
    }

    /**
     * @param array<string,mixed> $dados
     * @param array<string,mixed> $cfg
     * @return array{codigo_municipio:string,tributacao_issqn:int,tipo_retencao_issqn:int,aliquota:?float,alertas:list<string>}
     */
    private function resolverParametrosEmissao(array $dados, array $cfg): array
    {
        $codigoServico = $this->digitsOnly((string)($dados['codigo_servico'] ?? self::setting('codigo_trib_nacional', Env::get('NFSE_CODIGO_TRIB_NACIONAL', '') ?? '')));
        $competencia = date('Y-m-d');
        $aliquota = isset($dados['aliquota_iss']) && $dados['aliquota_iss'] !== ''
            ? round((float)$dados['aliquota_iss'], 2)
            : null;
        $alertas = [];

        try {
            if ($codigoServico !== '') {
                $diagnostico = $this->diagnosticarParametrosMunicipais($codigoServico, $competencia);
                $oficial = $diagnostico['aliquota']['aliquota'] ?? null;
                if ($aliquota === null && is_numeric($oficial)) {
                    $aliquota = round((float)$oficial, 2);
                }
                foreach ($diagnostico['alertas'] as $alerta) {
                    $alertas[] = $alerta;
                }
            }
        } catch (Throwable $e) {
            $alertas[] = 'Não foi possível consultar a parametrização municipal automaticamente: ' . $e->getMessage();
        }

        return [
            'codigo_municipio' => $cfg['codigo_municipio'],
            'tributacao_issqn' => 1,
            'tipo_retencao_issqn' => 1,
            'aliquota' => $aliquota,
            'alertas' => $alertas,
        ];
    }

    private function configuredEndpoint(TipoAmbiente $ambiente): string
    {
        $raw = (string)(
            $ambiente === TipoAmbiente::Producao
                ? (self::setting('endpoint_producao', Env::get('NFSE_ENDPOINT_PRODUCAO', '') ?? '') ?: '')
                : (self::setting('endpoint_homologacao', Env::get('NFSE_ENDPOINT_HOMOLOGACAO', '') ?? '') ?: '')
        );

        return self::normalizeEndpoint($raw);
    }

    /**
     * @param array<int,\Nfse\Dto\Http\AliquotaDto> $aliquotas
     */
    private function selecionarAliquotaVigente(array $aliquotas, string $competencia): ?\Nfse\Dto\Http\AliquotaDto
    {
        foreach ($aliquotas as $aliquota) {
            if ($this->competenciaDentroDaVigencia($competencia, $aliquota->dataInicio, $aliquota->dataFim)) {
                return $aliquota;
            }
        }

        return $aliquotas[0] ?? null;
    }

    private function competenciaDentroDaVigencia(string $competencia, ?string $inicio, ?string $fim): bool
    {
        $competenciaTs = strtotime($competencia . ' 12:00:00');
        $inicioTs = $inicio ? strtotime($inicio) : null;
        $fimTs = $fim ? strtotime($fim) : null;

        if ($competenciaTs === false) {
            return false;
        }

        if ($inicioTs !== null && $competenciaTs < $inicioTs) {
            return false;
        }

        if ($fimTs !== null && $competenciaTs > $fimTs) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string,mixed> $retencoes
     * @return array{artigo_sexto_habilitado:bool,municipal_ativa:bool,tipos:list<int>,detalhe:string}
     */
    private function avaliarRetencoesMunicipais(array $retencoes, string $codigoServico, string $competencia): array
    {
        $tipos = [];
        $municipalAtiva = false;

        foreach (($retencoes['retencoes']['retencoesMunicipais'] ?? []) as $retencaoMunicipal) {
            foreach (($retencaoMunicipal['servicos'] ?? []) as $servico) {
                if (($servico['codigoCompleto'] ?? null) !== $codigoServico) {
                    continue;
                }

                foreach (($servico['historico'] ?? []) as $historico) {
                    if ($this->competenciaDentroDaVigencia($competencia, $historico['dataInicioVigencia'] ?? null, $historico['dataFimVigencia'] ?? null)) {
                        $municipalAtiva = true;
                        foreach (($retencaoMunicipal['tiposRetencao'] ?? []) as $tipo) {
                            $tipos[] = (int)$tipo;
                        }
                    }
                }
            }
        }

        $artigoSexto = (bool)($retencoes['retencoes']['artigoSexto']['habilitado'] ?? false);
        $tipos = array_values(array_unique(array_filter($tipos, static fn ($tipo): bool => $tipo > 0)));

        $detalhe = $municipalAtiva
            ? 'Retenção municipal ativa para o serviço. Tipos retornados: ' . ($tipos !== [] ? implode(', ', $tipos) : 'não informados')
            : 'Sem retenção municipal ativa para o serviço na competência consultada.';

        if ($artigoSexto) {
            $detalhe .= ' Art. 6º habilitado pelo município.';
        }

        return [
            'artigo_sexto_habilitado' => $artigoSexto,
            'municipal_ativa' => $municipalAtiva,
            'tipos' => $tipos,
            'detalhe' => $detalhe,
        ];
    }

    /**
     * @param array<string,mixed> $regimes
     */
    private function contarRegimesEspeciais(array $regimes): int
    {
        $total = 0;
        foreach (($regimes['regimesEspeciais'] ?? []) as $grupo) {
            if (!is_array($grupo)) {
                continue;
            }
            foreach ($grupo as $itens) {
                if (is_array($itens)) {
                    $total += count($itens);
                }
            }
        }

        return $total;
    }

    private static function sdkAvailable(): bool
    {
        $autoload = self::sdkAutoloadPath();
        return is_file($autoload);
    }

    private static function bootSdk(): void
    {
        $autoload = self::sdkAutoloadPath();
        if (!is_file($autoload)) {
            throw new RuntimeException('Biblioteca NFS-e não encontrada em erp-nfse/vendor.');
        }

        require_once $autoload;
    }

    private static function sdkAutoloadPath(): string
    {
        return (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3)) . '/erp-nfse/vendor/autoload.php';
    }

    private static function setting(string $key, string $default = ''): string
    {
        if (self::$settingsCache === null) {
            try {
                self::$settingsCache = (new NfseSettingsService())->obter();
            } catch (Throwable) {
                self::$settingsCache = [];
            }
        }

        return (string)(self::$settingsCache[$key] ?? $default);
    }
}

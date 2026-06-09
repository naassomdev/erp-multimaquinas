<?php
declare(strict_types=1);

return [
    'ambiente'    => getenv('NFSE_AMBIENTE')  ?: 'homologacao',
    'cert_path'   => getenv('CERT_PATH')       ?: '',
    'cert_senha'  => getenv('CERT_PASSWORD')   ?: '',
    'cnpj_emissor'=> getenv('NFSE_PRESTADOR_CNPJ') ?: (getenv('CERT_CNPJ') ?: ''),
];

<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class UploadService
{
    public const KIND_FOTO  = 'fotos';
    public const KIND_VISTA = 'vista';

    /** @var array<string, array{mimes: array<string,string>, max: int}> */
    private const RULES = [
        self::KIND_FOTO => [
            'mimes' => [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
                'image/heic' => 'heic',
                'image/heif' => 'heif',
                'image/bmp'  => 'bmp',
            ],
            'max' => 25 * 1024 * 1024,
        ],
        self::KIND_VISTA => [
            'mimes' => [
                'application/pdf' => 'pdf',
                'image/jpeg'      => 'jpg',
                'image/png'       => 'png',
                'image/webp'      => 'webp',
                'image/heic'      => 'heic',
                'image/heif'      => 'heif',
            ],
            'max' => 25 * 1024 * 1024,
        ],
    ];

    private string $rootPath;
    private string $urlPrefix;

    public function __construct(?string $rootPath = null, string $urlPrefix = '/uploads')
    {
        $this->rootPath  = $rootPath ?? (BASE_PATH . '/public/uploads');
        $this->urlPrefix = rtrim($urlPrefix, '/');
    }

    /**
     * @param array{name:string, type:string, tmp_name:string, error:int, size:int} $upload
     * @return string URL relativa (ex: /uploads/os/ABC/0/fotos/abcd.jpg)
     */
    public function salvar(string $osId, int $equipIdx, string $kind, array $upload): string
    {
        $rules = self::RULES[$kind] ?? null;
        if ($rules === null) {
            throw new RuntimeException("Tipo de upload inválido: {$kind}");
        }

        $this->validateUpload($upload, $rules['mimes'], $rules['max']);

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = (string) $finfo->file($upload['tmp_name']);
        if ($detectedMime === 'application/octet-stream' && !empty($upload['type'])) {
            $detectedMime = strtolower((string)$upload['type']);
        }
        $ext = $rules['mimes'][$detectedMime] ?? 'bin';

        $safeOsId = $this->sanitizeOsId($osId);
        $filename = bin2hex(random_bytes(8)) . '.' . $ext;
        $relPath  = "os/{$safeOsId}/{$equipIdx}/{$kind}/{$filename}";
        $absPath  = $this->rootPath . '/' . $relPath;

        $this->ensureDir(dirname($absPath));

        if (!@move_uploaded_file($upload['tmp_name'], $absPath)) {
            throw new RuntimeException('Falha ao mover arquivo enviado');
        }
        @chmod($absPath, 0644);

        return $this->urlPrefix . '/' . $relPath;
    }

    /**
     * Upload genérico — para comprovantes, documentos avulsos, etc.
     * Aceita imagens comuns e PDFs.
     *
     * @param string $subDir  Subdiretório destino (ex: 'comprovantes')
     * @param array  $upload  Array $_FILES padrão
     * @return string URL relativa do arquivo salvo
     */
    public function salvarGenerico(string $subDir, array $upload): string
    {
        $allowedMimes = [
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'image/webp'      => 'webp',
            'application/pdf' => 'pdf',
        ];
        $maxBytes = 10 * 1024 * 1024; // 10 MB

        $this->validateUpload($upload, $allowedMimes, $maxBytes);

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = (string) $finfo->file($upload['tmp_name']);
        $ext = $allowedMimes[$detectedMime] ?? 'bin';

        $safeSub  = preg_replace('/[^a-zA-Z0-9_-]/', '', $subDir);
        $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $relPath  = "{$safeSub}/{$filename}";
        $absPath  = $this->rootPath . '/' . $relPath;

        $this->ensureDir(dirname($absPath));

        if (!@move_uploaded_file($upload['tmp_name'], $absPath)) {
            throw new RuntimeException('Falha ao mover arquivo enviado');
        }
        @chmod($absPath, 0644);

        return $this->urlPrefix . '/' . $relPath;
    }

    public function deletarPorUrl(string $url): bool
    {
        if ($url === '') return false;
        $prefix = $this->urlPrefix . '/';
        if (!str_starts_with($url, $prefix)) return false;

        $rel = substr($url, strlen($prefix));
        if ($rel === '' || str_contains($rel, '..')) return false;

        $abs = $this->rootPath . '/' . $rel;
        $absReal = realpath($abs);
        $rootReal = realpath($this->rootPath);
        if ($absReal === false || $rootReal === false) return false;
        if (!str_starts_with($absReal, $rootReal . DIRECTORY_SEPARATOR) && $absReal !== $rootReal) {
            return false;
        }

        if (!is_file($absReal)) return false;
        return @unlink($absReal);
    }

    /**
     * @param array<string, mixed> $upload
     * @param array<string,string> $allowedMimes
     */
    private function validateUpload(array $upload, array $allowedMimes, int $maxBytes): void
    {
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->mapUploadError($error));
        }

        $tmp = (string) ($upload['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Arquivo enviado é inválido');
        }

        $size = (int) ($upload['size'] ?? 0);
        if ($size <= 0) {
            throw new RuntimeException('Arquivo vazio');
        }
        if ($size > $maxBytes) {
            $mb = round($maxBytes / 1024 / 1024, 1);
            throw new RuntimeException("Arquivo excede o limite de {$mb} MB");
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = (string) $finfo->file($tmp);
        if ($detectedMime === 'application/octet-stream' && !empty($upload['type'])) {
            $detectedMime = strtolower((string)$upload['type']);
        }
        if (!isset($allowedMimes[$detectedMime])) {
            $aceitos = implode(', ', array_keys($allowedMimes));
            throw new RuntimeException("Tipo de arquivo não permitido ({$detectedMime}). Aceitos: {$aceitos}");
        }
    }

    private function sanitizeOsId(string $osId): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $osId)) {
            throw new RuntimeException("OS ID inválido para path: {$osId}");
        }
        return $osId;
    }

    private function ensureDir(string $dir): void
    {
        if (is_dir($dir)) return;
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Não foi possível criar diretório: {$dir}");
        }
    }

    private function mapUploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Arquivo maior que o permitido',
            UPLOAD_ERR_PARTIAL                        => 'Upload incompleto',
            UPLOAD_ERR_NO_FILE                        => 'Nenhum arquivo enviado',
            UPLOAD_ERR_NO_TMP_DIR                     => 'Diretório temporário ausente no servidor',
            UPLOAD_ERR_CANT_WRITE                     => 'Falha ao gravar no servidor',
            UPLOAD_ERR_EXTENSION                      => 'Upload bloqueado por extensão PHP',
            default                                   => 'Erro desconhecido no upload',
        };
    }
}

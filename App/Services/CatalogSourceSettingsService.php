<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\ConfiguracaoRepository;

final class CatalogSourceSettingsService
{
    private const CONFIG_KEY = 'catalogo_fontes_registry';

    /**
     * @var array<int, array<string, mixed>>
     */
    private const DEFAULT_BUILTINS = [
        [
            'id' => 'felap',
            'slug' => 'felap',
            'label' => 'Felap (BR — PDF)',
            'driver' => 'felap',
            'kind' => 'adapter',
            'enabled' => true,
            'priority' => 10,
            'site_url' => 'https://www.ferramentasfelap.com.br/pecasdereposicao',
            'description' => 'PDFs de vistas explodidas por marca e modelo.',
            'search_label' => 'Filtrar (opcional)',
            'search_placeholder' => 'Filtrar modelos da marca selecionada (ex.: DCD996)',
            'brand_label' => 'Marca',
            'brand_param' => 'marca',
            'brand_options' => [
                ['value' => '1', 'label' => 'Bosch'],
                ['value' => '2', 'label' => 'DeWalt'],
                ['value' => '3', 'label' => 'Makita'],
                ['value' => '4', 'label' => 'Metabo'],
                ['value' => '5', 'label' => 'Black & Decker'],
                ['value' => '6', 'label' => 'Skil'],
                ['value' => '7', 'label' => 'Dremel'],
                ['value' => '8', 'label' => 'Milwaukee'],
                ['value' => '9', 'label' => 'Hitachi / HiKOKI'],
                ['value' => '10', 'label' => 'Ryobi'],
            ],
        ],
        [
            'id' => 'tsn',
            'slug' => 'tsn',
            'label' => 'ToolServiceNet — Stanley B&D',
            'driver' => 'tsn',
            'kind' => 'adapter',
            'enabled' => true,
            'priority' => 20,
            'site_url' => 'https://www.toolservicenet.com/en',
            'description' => 'Catálogo técnico com modelos da linha Stanley Black & Decker.',
            'search_label' => 'Filtrar (opcional)',
            'search_placeholder' => 'Filtrar modelos do brand selecionado',
            'brand_label' => 'Brand',
            'brand_param' => 'brand',
            'brand_options' => [
                ['value' => 'DW', 'label' => 'DeWalt (DW)'],
                ['value' => 'CRM', 'label' => 'Craftsman (CRM)'],
                ['value' => 'BD', 'label' => 'Black & Decker (BD)'],
                ['value' => 'BT', 'label' => 'Bostitch (BT)'],
                ['value' => 'PC', 'label' => 'Porter Cable (PC)'],
                ['value' => 'PR', 'label' => 'Proto (PR)'],
            ],
        ],
        [
            'id' => 'bosch',
            'slug' => 'bosch',
            'label' => 'Bosch Tool Service',
            'driver' => 'bosch',
            'kind' => 'adapter',
            'enabled' => true,
            'priority' => 30,
            'site_url' => 'https://www.boschtoolservice.com/br/pt/bosch-pt/spareparts/search',
            'description' => 'Busca por modelo ou número de tipo Bosch / Dremel.',
            'search_label' => 'Modelo / busca',
            'search_placeholder' => 'Nome do modelo ou número de tipo (10 dígitos)',
            'mode_label' => 'Buscar por',
            'mode_param' => 'modo',
            'mode_options' => [
                ['value' => 'modelo', 'label' => 'Nome do modelo (ex.: GSB 13 RE)'],
                ['value' => 'typenr', 'label' => 'Número de tipo (10 dígitos)'],
            ],
        ],
        [
            'id' => 'milwaukee',
            'slug' => 'milwaukee',
            'label' => 'Milwaukee Tool',
            'driver' => 'milwaukee',
            'kind' => 'adapter',
            'enabled' => true,
            'priority' => 40,
            'site_url' => 'https://www.milwaukeetool.com/support/manuals-and-downloads',
            'description' => 'Manuais e PDFs técnicos por modelo.',
            'search_label' => 'Modelo / busca',
            'search_placeholder' => 'Modelo Milwaukee (ex.: 2804-20)',
        ],
    ];

    public function __construct(
        private readonly ConfiguracaoRepository $repo = new ConfiguracaoRepository(),
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function obter(): array
    {
        $rows = $this->repo->listarPorPrefixo('catalogo_fontes_');
        $raw = $rows[self::CONFIG_KEY] ?? '';
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        $savedBuiltins = [];
        foreach (($decoded['builtins'] ?? []) as $item) {
            if (!is_array($item) || !isset($item['slug'])) {
                continue;
            }
            $savedBuiltins[(string) $item['slug']] = $item;
        }

        $builtins = [];
        foreach (self::DEFAULT_BUILTINS as $builtin) {
            $saved = $savedBuiltins[$builtin['slug']] ?? [];
            $builtins[] = $this->normalizeBuiltin(array_merge($builtin, is_array($saved) ? $saved : []));
        }

        $extras = [];
        foreach (($decoded['extras'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $normalized = $this->normalizeExtra($item);
            if ($normalized !== null) {
                $extras[] = $normalized;
            }
        }

        usort($builtins, static fn (array $a, array $b): int => ($a['priority'] <=> $b['priority']) ?: strcmp((string) $a['label'], (string) $b['label']));
        usort($extras, static fn (array $a, array $b): int => ($a['priority'] <=> $b['priority']) ?: strcmp((string) $a['label'], (string) $b['label']));

        return [
            'builtins' => $builtins,
            'extras' => $extras,
            'active' => $this->ativasFrom($builtins, $extras),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listarAtivas(): array
    {
        $settings = $this->obter();
        return $settings['active'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buscarAtiva(string $id): ?array
    {
        foreach ($this->listarAtivas() as $fonte) {
            if ((string) ($fonte['id'] ?? '') === $id) {
                return $fonte;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function salvar(array $input): void
    {
        $savedBuiltins = [];
        $enabled = $input['builtin_enabled'] ?? [];
        $labels = $input['builtin_label'] ?? [];
        $priorities = $input['builtin_priority'] ?? [];

        foreach (self::DEFAULT_BUILTINS as $builtin) {
            $slug = (string) $builtin['slug'];
            $savedBuiltins[] = [
                'slug' => $slug,
                'enabled' => isset($enabled[$slug]) && (string) $enabled[$slug] === '1',
                'label' => $this->cleanLabel($labels[$slug] ?? $builtin['label'], (string) $builtin['label']),
                'priority' => $this->cleanPriority($priorities[$slug] ?? $builtin['priority'], (int) $builtin['priority']),
            ];
        }

        $extraEnabled = $input['extra_enabled'] ?? [];
        $extraSlugs = $input['extra_slug'] ?? [];
        $extraLabels = $input['extra_label'] ?? [];
        $extraKinds = $input['extra_kind'] ?? [];
        $extraPriorities = $input['extra_priority'] ?? [];
        $extraBaseUrls = $input['extra_base_url'] ?? [];
        $extraTemplates = $input['extra_url_template'] ?? [];
        $extraNotes = $input['extra_notes'] ?? [];

        $count = max(
            is_countable($extraSlugs) ? count($extraSlugs) : 0,
            is_countable($extraLabels) ? count($extraLabels) : 0,
            is_countable($extraTemplates) ? count($extraTemplates) : 0
        );

        $extras = [];
        for ($i = 0; $i < $count; $i++) {
            $normalized = $this->normalizeExtra([
                'enabled' => isset($extraEnabled[$i]) && (string) $extraEnabled[$i] === '1',
                'slug' => $extraSlugs[$i] ?? '',
                'label' => $extraLabels[$i] ?? '',
                'kind' => $extraKinds[$i] ?? 'search_page',
                'priority' => $extraPriorities[$i] ?? ($i + 1) * 10,
                'site_url' => $extraBaseUrls[$i] ?? '',
                'url_template' => $extraTemplates[$i] ?? '',
                'description' => $extraNotes[$i] ?? '',
            ]);

            if ($normalized !== null) {
                $extras[] = $normalized;
            }
        }

        $payload = [
            self::CONFIG_KEY => json_encode([
                'builtins' => $savedBuiltins,
                'extras' => $extras,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        ];

        $this->repo->salvarMuitos($payload);
    }

    /**
     * @param array<string, mixed> $fonte
     * @return array<string, mixed>
     */
    public function gerarResultadoCustom(array $fonte, string $modelo, string $marca = '', string $query = ''): array
    {
        $modelo = trim($modelo);
        $marca = trim($marca);
        $search = trim($query !== '' ? $query : trim($marca . ' ' . $modelo));
        $type = (string) ($fonte['kind'] ?? 'search_page');
        $label = (string) ($fonte['label'] ?? 'Fonte externa');

        $replacements = [
            '{marca}' => $marca,
            '{modelo}' => $modelo,
            '{query}' => $search,
            '{marca_url}' => rawurlencode($marca),
            '{modelo_url}' => rawurlencode($modelo),
            '{query_url}' => rawurlencode($search),
            '{marca_slug}' => $this->slugify($marca),
            '{modelo_slug}' => $this->slugify($modelo),
            '{query_slug}' => $this->slugify($search),
        ];

        $url = strtr((string) ($fonte['url_template'] ?? ''), $replacements);
        $title = $type === 'direct_pdf'
            ? sprintf('%s · %s', $label, $modelo !== '' ? $modelo : $search)
            : sprintf('Abrir busca em %s', $label);

        return [
            'modelo' => $modelo,
            'titulo' => $title,
            'tipo' => $type === 'direct_pdf' ? 'PDF configurado' : 'Busca externa',
            'urlPDF' => $url,
            'fonte' => (string) ($fonte['id'] ?? ''),
            'canVincular' => $type === 'direct_pdf',
            'description' => (string) ($fonte['description'] ?? ''),
            'siteUrl' => (string) ($fonte['site_url'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function normalizeBuiltin(array $source): array
    {
        $source['id'] = (string) ($source['id'] ?? $source['slug'] ?? '');
        $source['slug'] = (string) ($source['slug'] ?? $source['id'] ?? '');
        $source['label'] = $this->cleanLabel($source['label'] ?? '', ucfirst((string) $source['slug']));
        $source['enabled'] = $this->toBool($source['enabled'] ?? true);
        $source['priority'] = $this->cleanPriority($source['priority'] ?? 10, 10);
        $source['kind'] = 'adapter';
        $source['driver'] = (string) ($source['driver'] ?? $source['slug']);
        $source['site_url'] = trim((string) ($source['site_url'] ?? ''));
        $source['description'] = trim((string) ($source['description'] ?? ''));
        $source['search_label'] = trim((string) ($source['search_label'] ?? 'Modelo / busca'));
        $source['search_placeholder'] = trim((string) ($source['search_placeholder'] ?? 'Digite o modelo...'));
        $source['brand_options'] = is_array($source['brand_options'] ?? null) ? array_values($source['brand_options']) : [];
        $source['mode_options'] = is_array($source['mode_options'] ?? null) ? array_values($source['mode_options']) : [];

        return $source;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>|null
     */
    private function normalizeExtra(array $source): ?array
    {
        $slug = $this->slugify((string) ($source['slug'] ?? ''));
        $label = $this->cleanLabel($source['label'] ?? '', '');
        $urlTemplate = trim((string) ($source['url_template'] ?? ''));

        if ($slug === '' && $label === '' && $urlTemplate === '') {
            return null;
        }

        if ($slug === '' || $label === '' || $urlTemplate === '') {
            return null;
        }

        $kind = (string) ($source['kind'] ?? 'search_page');
        if (!in_array($kind, ['direct_pdf', 'search_page'], true)) {
            $kind = 'search_page';
        }

        $siteUrl = trim((string) ($source['site_url'] ?? ''));
        if ($siteUrl === '') {
            $siteUrl = $urlTemplate;
        }

        return [
            'id' => 'custom:' . $slug,
            'slug' => $slug,
            'label' => $label,
            'driver' => 'custom',
            'kind' => $kind,
            'enabled' => $this->toBool($source['enabled'] ?? true),
            'priority' => $this->cleanPriority($source['priority'] ?? 100, 100),
            'site_url' => $siteUrl,
            'description' => trim((string) ($source['description'] ?? 'Fonte complementar configurada pelo administrador.')),
            'search_label' => 'Marca / modelo',
            'search_placeholder' => 'Ex.: DeWalt DCD996',
            'url_template' => $urlTemplate,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $builtins
     * @param array<int, array<string, mixed>> $extras
     * @return array<int, array<string, mixed>>
     */
    private function ativasFrom(array $builtins, array $extras): array
    {
        $active = [];

        foreach (array_merge($builtins, $extras) as $source) {
            if (!$this->toBool($source['enabled'] ?? false)) {
                continue;
            }
            $active[] = $source;
        }

        usort($active, static fn (array $a, array $b): int => ($a['priority'] <=> $b['priority']) ?: strcmp((string) $a['label'], (string) $b['label']));

        return array_values($active);
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }

    private function cleanPriority(mixed $value, int $default): int
    {
        return max(1, (int) preg_replace('/\D+/', '', (string) $value) ?: $default);
    }

    private function cleanLabel(mixed $value, string $default): string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : $default;
    }

    private function slugify(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $ascii = is_string($ascii) ? $ascii : $value;
        $ascii = strtolower($ascii);
        $ascii = preg_replace('/[^a-z0-9]+/', '-', $ascii) ?? '';
        return trim($ascii, '-');
    }
}

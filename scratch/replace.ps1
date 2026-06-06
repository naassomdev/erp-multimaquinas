$dir = "d:\Projeto ERP OS\App\multimaquinas.site\App\Views"
$files = Get-ChildItem -Path $dir -Recurse -Filter *.php

$replacements = @(
    @{ Pattern = 'class="group bg-white dark:bg-card-dark rounded-xl border border-slate-200 dark:border-slate-700/60 shadow-soft p-5 flex flex-col justify-between transition-all hover:shadow-md hover:-translate-y-0.5 hover:border-slate-300 dark:hover:border-slate-600"'; Replacement = 'class="group erp-card erp-card-kpi"' },
    @{ Pattern = 'bg-white dark:bg-card-dark border border-slate-200 dark:border-slate-700/60 rounded-xl shadow-soft overflow-hidden'; Replacement = 'erp-card' },
    @{ Pattern = 'bg-white dark:bg-card-dark border border-slate-200 dark:border-slate-700/60 rounded-xl shadow-soft'; Replacement = 'erp-card' },
    @{ Pattern = 'class="w-full text-left text-sm"'; Replacement = 'class="erp-table"' },
    @{ Pattern = '<thead class="bg-slate-50 dark:bg-slate-900/40 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">'; Replacement = '<thead>' },
    @{ Pattern = '<tr class="hover:bg-slate-50/70 dark:hover:bg-slate-800/40 transition-colors">'; Replacement = '<tr>' },
    @{ Pattern = 'class="text-2xl font-bold text-slate-900 dark:text-white tracking-tight"'; Replacement = 'class="erp-page-title"' },
    @{ Pattern = 'class="text-sm text-slate-500 dark:text-slate-400 mt-1"'; Replacement = 'class="erp-page-subtitle"' },
    @{ Pattern = 'class="text-sm font-semibold text-slate-900 dark:text-white"'; Replacement = 'class="text-lg font-bold text-slate-900 dark:text-white"' },
    @{ Pattern = 'class="px-6 py-3.5"'; Replacement = '' },
    @{ Pattern = 'class="px-6 py-3.5 text-right"'; Replacement = 'class="text-right"' },
    @{ Pattern = 'class="px-6 py-3.5 text-center"'; Replacement = 'class="text-center"' },
    @{ Pattern = 'class="px-6 py-4 '; Replacement = 'class="' }
)

$count = 0
foreach ($file in $files) {
    $content = [IO.File]::ReadAllText($file.FullName, [System.Text.Encoding]::UTF8)
    $newContent = $content
    
    foreach ($r in $replacements) {
        $newContent = $newContent.Replace($r.Pattern, $r.Replacement)
    }
    
    if ($content -cne $newContent) {
        [IO.File]::WriteAllText($file.FullName, $newContent, [System.Text.Encoding]::UTF8)
        Write-Host "Updated: $($file.FullName)"
        $count++
    }
}
Write-Host "Total files updated: $count"

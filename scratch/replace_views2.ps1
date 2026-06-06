$dir = "d:\Projeto ERP OS\App\multimaquinas.site\App\Views"
$files = Get-ChildItem -Path $dir -Recurse -Filter *.php

$variations = @(
    "bg-white dark:bg-card-dark rounded-xl border border-slate-200 dark:border-slate-700/60 shadow-soft overflow-hidden",
    "bg-white dark:bg-card-dark rounded-xl border border-slate-200 dark:border-slate-700/60 shadow-soft",
    "bg-white dark:bg-card-dark border border-slate-200 dark:border-slate-700/60 rounded-xl shadow-soft overflow-hidden",
    "bg-white dark:bg-card-dark border border-slate-200 dark:border-slate-700/60 rounded-xl shadow-soft",
    "bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm"
)

$count = 0
foreach ($file in $files) {
    $content = [IO.File]::ReadAllText($file.FullName, [System.Text.Encoding]::UTF8)
    $newContent = $content
    
    # Tabela 
    $newContent = [regex]::Replace($newContent, 'class="w-full text-left text-sm"', 'class="erp-table"')
    $newContent = [regex]::Replace($newContent, '<thead class="bg-slate-50[^"]*text-slate-500[^"]*">', '<thead>')

    # Remover classes de hover padrão em TRs para evitar conflito com erp-table
    $newContent = [regex]::Replace($newContent, 'hover:bg-slate-50/70 dark:hover:bg-slate-800/40 transition-colors', '')

    # Substituir os blocos de cards
    foreach ($var in $variations) {
        $newContent = $newContent.Replace($var, "erp-card")
    }

    # Titles
    $newContent = [regex]::Replace($newContent, 'class="text-2xl font-bold text-slate-900 dark:text-white tracking-tight"', 'class="erp-page-title"')
    $newContent = [regex]::Replace($newContent, 'class="text-sm text-slate-500 dark:text-slate-400 mt-1"', 'class="erp-page-subtitle"')

    # Fix class="erp-card erp-card-kpi p-4" which might have happened
    # We just want to make sure it's clean

    if ($content -cne $newContent) {
        [IO.File]::WriteAllText($file.FullName, $newContent, [System.Text.Encoding]::UTF8)
        $count++
    }
}
Write-Host "Total files updated: $count"

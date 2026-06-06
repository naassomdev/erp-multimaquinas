<?php
$dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../App/Views'));

$replacements = [
    // KPI Cards in Dashboard
    '/class="group bg-white dark:bg-card-dark rounded-xl border border-slate-200 dark:border-slate-700\/60 shadow-soft p-5 flex flex-col justify-between transition-all hover:shadow-md hover:-translate-y-0\.5 hover:border-slate-300 dark:hover:border-slate-600"/' 
        => 'class="group erp-card erp-card-kpi"',

    // Standard Cards / Wrappers
    '/bg-white dark:bg-card-dark border border-slate-200 dark:border-slate-700\/60 rounded-xl shadow-soft overflow-hidden/' 
        => 'erp-card',
    
    // Some variations of the standard card
    '/bg-white dark:bg-card-dark border border-slate-200 dark:border-slate-700\/60 rounded-xl shadow-soft/'
        => 'erp-card',

    // Tables
    '/class="w-full text-left text-sm"/' 
        => 'class="erp-table"',
        
    // Table Headers
    '/<thead class="bg-slate-50 dark:bg-slate-900\/40 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">/'
        => '<thead>',
        
    // Table rows
    '/<tr class="hover:bg-slate-50\/70 dark:hover:bg-slate-800\/40 transition-colors">/'
        => '<tr>',

    // Page titles (e.g., <h1 class="text-2xl font-bold text-slate-900 dark:text-white tracking-tight">)
    '/class="text-2xl font-bold text-slate-900 dark:text-white tracking-tight"/'
        => 'class="erp-page-title"',
        
    // Page subtitles (e.g., <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">)
    '/class="text-sm text-slate-500 dark:text-slate-400 mt-1"/'
        => 'class="erp-page-subtitle"',
        
    // Sub-titles in sections (e.g., <h2 class="text-sm font-semibold text-slate-900 dark:text-white">)
    '/class="text-sm font-semibold text-slate-900 dark:text-white"/'
        => 'class="text-lg font-bold text-slate-900 dark:text-white"',
        
    // Reduce excessive padding in table headers manually styled
    '/class="px-6 py-3\.5"/' => '',
    '/class="px-6 py-3\.5 text-right"/' => 'class="text-right"',
    '/class="px-6 py-3\.5 text-center"/' => 'class="text-center"',
    
    // Reduce excessive padding in table cells
    '/class="px-6 py-4 /' => 'class="',
];

$count = 0;
foreach ($dir as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $content = file_get_contents($file->getPathname());
        $newContent = $content;
        
        foreach ($replacements as $pattern => $replacement) {
            $newContent = preg_replace($pattern, $replacement, $newContent);
        }
        
        if ($content !== $newContent) {
            file_put_contents($file->getPathname(), $newContent);
            echo "Updated: " . $file->getPathname() . "\n";
            $count++;
        }
    }
}

echo "Total files updated: $count\n";

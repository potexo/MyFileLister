<?php
// =========================================================
// 1. FUNCIÓN AUXILIAR: TAMAÑO HUMANO (PHP 7.3 COMPATIBLE)
// =========================================================
function formatSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, ($pow > 0 ? 2 : 0)) . ' ' . $units[$pow];
}

// =========================================================
// 2. CONFIGURACIÓN Y PARÁMETROS
// =========================================================
$appVersion  = '1.7.1';
$rootDir     = rtrim(realpath(__DIR__), DIRECTORY_SEPARATOR);
$currentDir  = $rootDir;
$dirParam    = '';
$sort        = isset($_GET['sort']) && in_array($_GET['sort'], ['name', 'size', 'modified']) ? $_GET['sort'] : 'name';
$order       = isset($_GET['order']) && $_GET['order'] === 'desc' ? 'desc' : 'asc';
$allowedExts = ['iso','dmg','apk','msi','cmd','bat','sh','jar','7z','exe','gz','html','htm','txt','jpg','jpeg','png','gif','pdf','zip','rar','doc','docx','xls','xlsx','csv','ppt','pptx','ods','odt','odp'];
$maxZipSize  = 500 * 1024 * 1024; // Límite de 500MB en bytes

// Gestión segura de navegación ?dir=
if (isset($_GET['dir']) && $_GET['dir'] !== '' && $_GET['dir'] !== '/') {
    $testDir = $rootDir . DIRECTORY_SEPARATOR . $_GET['dir'];
    $resolved = realpath($testDir);
    if ($resolved !== false && is_dir($resolved) && strpos(strtolower($resolved), strtolower($rootDir . DIRECTORY_SEPARATOR)) === 0) {
        $currentDir = $resolved;
        $dirParam   = $_GET['dir'];
    }
}

$filesMap = [];
$dirsList = [];
$error    = '';

// =========================================================
// 3. ESCANEO DEL DIRECTORIO ACTUAL
// =========================================================
if (is_dir($currentDir)) {
    $iterator = new DirectoryIterator($currentDir);
    foreach ($iterator as $entry) {
        if ($entry->isDot()) continue;
        $name = $entry->getFilename();

        if ($entry->isDir()) {
            $dirsList[] = [
                'name' => $name,
                'path' => $currentDir . DIRECTORY_SEPARATOR . $name,
                'mod'  => date('Y-m-d H:i', $entry->getMTime())
            ];
        } elseif ($entry->isFile()) {
            if ($name === 'index.html') continue;
            
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($ext === '' || in_array($ext, $allowedExts, true)) {
                if ($name === 'index.php') continue;
                $sizeBytes = filesize($entry->getPathname());
                $filesMap[$name] = [
                    'name'       => $name,
                    'size'       => formatSize($sizeBytes),
                    'size_bytes' => $sizeBytes,
                    'modified'   => date('Y-m-d H:i', $entry->getMTime()),
                    'full'       => $entry->getPathname(),
                    'selectable' => ($sizeBytes <= $maxZipSize)
                ];
            }
        }
    }
} else {
    $error = 'Directorio no válido o sin permisos de lectura.';
}

// =========================================================
// 4. ORDENACIÓN DINÁMICA (INICIAL PARA LA CARGA)
// =========================================================
uasort($filesMap, function($a, $b) use ($sort, $order) {
    $cmp = 0;
    if ($sort === 'name') {
        $cmp = strnatcasecmp($a['name'], $b['name']);
    } elseif ($sort === 'size') {
        $cmp = $a['size_bytes'] <=> $b['size_bytes'];
    } else {
        $cmp = strcmp($a['modified'], $b['modified']);
    }
    return $order === 'desc' ? -$cmp : $cmp;
});

usort($dirsList, function($a, $b) { return strcasecmp($a['name'], $b['name']); });

// =========================================================
// 5. DESCARGA DIRECTA (GET ?dl=...)
// =========================================================
if (isset($_GET['dl']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $reqFile = urldecode($_GET['dl']);
    $rootPrefix = strtolower($rootDir . DIRECTORY_SEPARATOR);
    $dlDir = $rootDir;
    if (isset($_GET['dir']) && $_GET['dir'] !== '' && $_GET['dir'] !== '/') {
        $t = realpath($rootDir . DIRECTORY_SEPARATOR . $_GET['dir']);
        if ($t !== false && strpos(strtolower($t), $rootPrefix) === 0) $dlDir = $t;
    }
    $full = $dlDir . DIRECTORY_SEPARATOR . $reqFile;
    $resolved = realpath($full);

    if ($resolved !== false && is_file($resolved) && is_readable($resolved) && strpos(strtolower($resolved), $rootPrefix) === 0) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($resolved) . '"');
        header('Content-Length: ' . filesize($resolved));
        header('Pragma: no-cache');
        header('Expires: 0');
        readfile($resolved);
        exit;
    }
    http_response_code(404);
    die('Archivo no encontrado o no permitido.');
}

// =========================================================
// 6. DESCARGA ZIP MASIVA (POST)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sort  = isset($_POST['sort']) && in_array($_POST['sort'], ['name', 'size', 'modified']) ? $_POST['sort'] : $sort;
    $order = isset($_POST['order']) && in_array($_POST['order'], ['asc', 'desc']) ? $_POST['order'] : $order;

    $zipDir = $rootDir;
    if (isset($_POST['current_dir']) && $_POST['current_dir'] !== '') {
        $t = realpath($rootDir . DIRECTORY_SEPARATOR . $_POST['current_dir']);
        if ($t !== false && strpos(strtolower($t), strtolower($rootDir . DIRECTORY_SEPARATOR)) === 0) $zipDir = $t;
    }

    if (isset($_POST['files']) && is_array($_POST['files'])) {
        $selected = array_intersect($_POST['files'], array_keys($filesMap));
        if (empty($selected)) {
            $error = 'No se han seleccionado archivos.';
        } elseif (!class_exists('ZipArchive')) {
            $error = 'Error: La extensión ZipArchive no está instalada. Instala php-zip.';
        } else {
            $zip = new ZipArchive();
            $tmpZip = sys_get_temp_dir() . '/archive_' . uniqid() . '.zip';
            
            if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                foreach ($selected as $name) {
                    if (isset($filesMap[$name]['selectable']) && $filesMap[$name]['selectable']) {
                        $zip->addFile($zipDir . DIRECTORY_SEPARATOR . $name, $name);
                    }
                }
                $zip->close();
            }

            if (file_exists($tmpZip)) {
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="selected_files.zip"');
                header('Content-Length: ' . filesize($tmpZip));
                readfile($tmpZip);
                unlink($tmpZip);
                exit;
            }
            $error = 'Error al crear el archivo ZIP.';
        }
    }
}

// =========================================================
// 7. GENERACIÓN DEL BREADCRUMB
// =========================================================
$qs = '&sort=' . urlencode($sort) . '&order=' . urlencode($order);

function buildBreadcrumb($dirParam, $qs) {
    $html = '<a href="?dir=' . urlencode('') . $qs . '" class="crumb">Raíz</a>';
    if ($dirParam !== '' && $dirParam !== '/') {
        $segments = array_filter(explode('/', $dirParam), 'strlen');
        $pathBuilder = '';
        foreach ($segments as $idx => $seg) {
            $pathBuilder = ($pathBuilder === '' ? $seg : $pathBuilder . '/' . $seg);
            $isLast = ($idx === count($segments) - 1);
            $url = '?dir=' . urlencode($pathBuilder) . $qs;
            $html .= '<span class="separator">/</span>';
            if ($isLast) {
                $html .= '<span class="crumb current">' . htmlspecialchars($seg) . '</span>';
            } else {
                $html .= '<a href="' . $url . '" class="crumb">' . htmlspecialchars($seg) . '</a>';
            }
        }
    }
    return $html;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestor de Archivos</title>
    <style>
        :root {
            /* MODO OSCURO POR DEFECTO */
            --bg: #0f172a;
            --surface: #1e293b;
            --border: #334155;
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --text: #f1f5f9;
            --text-muted: #94a3b8;
            --dir-bg: #1e293b;
            --input-bg: #0f172a;
        }

        .light-theme {
            /* MODO CLARO */
            --bg: #f8fafc;
            --surface: #ffffff;
            --border: #e2e8f0;
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --text: #0f172a;
            --text-muted: #64748b;
            --dir-bg: #f1f5f9;
            --input-bg: #ffffff;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            padding: 1.5rem;
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        .container {
            max-width: 960px;
            margin: 0 auto;
            background: var(--surface);
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            padding: 1.5rem;
            transition: background-color 0.3s ease, box-shadow 0.3s ease;
        }
        
        /* 🔹 HEADER STICKY REFORZADO */
        .sticky-header {
            position: -webkit-sticky;
            position: sticky;
            top: 0;
            z-index: 1000;
            background: var(--surface);
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
            margin-bottom: 1rem;
            box-shadow: 0 4px 12px -2px rgba(0,0,0,0.15);
        }
        
        .breadcrumb {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
            padding: 0.5rem 0.75rem;
            background: var(--bg);
            border-radius: 8px;
            font-size: 0.85rem;
        }
        .crumb { color: var(--primary); text-decoration: none; font-weight: 500; }
        .crumb:hover { text-decoration: underline; }
        .crumb.current { color: var(--text); font-weight: 600; }
        .separator { color: var(--text-muted); margin: 0 0.25rem; user-select: none; }
        
        .toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 0.5rem;
            flex-wrap: wrap;
            background: var(--bg);
            padding: 0.75rem;
            border-radius: 8px;
        }
        .toolbar .search-group {
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: 0.5rem;
            flex: 1;
            min-width: 200px;
            max-width: 320px;
        }
        .toolbar .search-box { position: relative; flex: 1; }
        .toolbar .search-box input {
            width: 100%;
            padding: 0.5rem 2.5rem 0.5rem 0.8rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.85rem;
            background: var(--input-bg);
            color: var(--text);
        }
        .toolbar .clear-btn {
            position: absolute;
            right: 0.5rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
        }
        .toolbar .btn {
            background: var(--primary);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            font-size: 0.85rem;
            white-space: nowrap;
        }
        .toolbar .btn:hover { background: var(--primary-hover); }
        .toolbar .btn:disabled { background: #cbd5e1; cursor: not-allowed; }
        .toolbar .btn-back {
            background: var(--surface);
            color: var(--text);
            border: 1px solid var(--border);
            padding: 0.5rem 0.8rem;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.85rem;
            white-space: nowrap;
        }
        .toolbar .btn-theme {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text);
            padding: 0.5rem 0.8rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.85rem;
            white-space: nowrap;
        }
        
        .counter-text {
            font-size: 0.75rem;
            color: var(--text-muted);
            font-weight: 600;
            white-space: nowrap;
        }
        
        .header { margin-bottom: 0.5rem; }
        h1 { font-size: 1.4rem; display: flex; align-items: center; gap: 0.5rem; }
        .badge { background: var(--border); padding: 0.2rem 0.6rem; border-radius: 99px; font-size: 0.75rem; color: var(--text-muted); }
        
        .alert { background: #fee2e2; color: #991b1b; padding: 0.75rem; border-radius: 8px; margin-bottom: 1rem; display: none; }
        .alert.show { display: block; }
        
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        th, td { padding: 0.5rem 0.7rem; border-bottom: 1px solid var(--border); text-align: left; vertical-align: middle; }
        th { background: var(--bg); font-weight: 600; color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; }
        tr:hover td { background: var(--bg); }
        th:nth-child(3), td:nth-child(3) { text-align: right; }
        
        .dir-row td { background: var(--dir-bg); }
        .dir-link { color: var(--text); text-decoration: none; font-weight: 600; }
        .file-link { color: var(--primary); text-decoration: none; font-weight: 500; }
        
        input[type="checkbox"] { width: 15px; height: 15px; cursor: pointer; accent-color: var(--primary); }
        input[disabled] { opacity: 0.4; cursor: not-allowed; }
        
        .sort-link { color: var(--text); text-decoration: none; cursor: pointer; font-weight: 600; display: inline-flex; align-items: center; gap: 0.2rem; }
        .sort-link.active { color: var(--primary); text-decoration: underline; }
        .sort-arrow { margin-left: 0.3rem; font-size: 0.7rem; opacity: 0.7; }
        
        /* 🔹 PIE DE PÁGINA */
        .footer-info {
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
            text-align: right;
            font-size: 0.75rem;
            color: var(--text-muted);
        }
        
        @media (max-width: 640px) {
            table, thead, tbody, th, td, tr { display: block; }
            thead { position: absolute; top: -9999px; left: -9999px; }
            tr { margin-bottom: 0.6rem; border: 1px solid var(--border); border-radius: 8px; padding: 0.5rem; }
            td { position: relative; padding-left: 35%; border-bottom: none; }
            td:before { position: absolute; left: 0.6rem; top: 0.4rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; font-size: 0.65rem; }
            td:nth-child(1):before { content: 'Sel.'; }
            td:nth-child(2):before { content: 'Nombre'; }
            td:nth-child(3):before { content: 'Tamaño'; }
            td:nth-child(4):before { content: 'Modificado'; }
            th:nth-child(3), td:nth-child(3) { text-align: left; }
            .toolbar { flex-direction: column; align-items: stretch; }
            .toolbar .search-group { max-width: none; flex-direction: row; flex-wrap: wrap; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="sticky-header">
            <nav class="breadcrumb">
                <?= buildBreadcrumb($dirParam, $qs) ?>
            </nav>

            <div class="toolbar">
                <div class="search-group">
                    <div class="search-box">
                        <input type="text" id="searchInput" placeholder="🔍 Buscar por nombre..." autocomplete="off">
                        <button type="button" id="clearSearch" class="clear-btn">✕</button>
                    </div>
                    <div class="counter-text" id="counter">0 seleccionados</div>
                </div>
                
                <div class="button-group">
                    <button type="button" class="btn" id="downloadBtn" disabled>📦 Descargar ZIP</button>
                    <button type="button" class="btn-theme" id="themeToggle">☀️ Modo claro</button>
                    <?php if ($dirParam !== ''): 
                        $backDir = dirname($dirParam);
                        $backDir = ($backDir === '.') ? '' : $backDir;
                    ?>
                        <a href="?dir=<?= urlencode($backDir) ?>&sort=<?= urlencode($sort) ?>&order=<?= urlencode($order) ?>" class="btn-back">⬅️ Volver</a>
                    <?php endif; ?>
                </div>
            </div>
            </div>

            <div class="header">
                <h1>📁 <?= htmlspecialchars($dirParam ?: 'Raíz') ?> <span class="badge"><?= count($dirsList) ?> carpetas · <?= count($filesMap) ?> archivos</span></h1>
            </div>

        <?php if (!empty($error)): ?>
            <div class="alert show"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" id="fileForm">
            <input type="hidden" name="current_dir" value="<?= htmlspecialchars($dirParam) ?>">
            <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
            <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">
            
            <table>
                <thead>
                    <tr>
                        <th style="width:35px"><input type="checkbox" id="select-all"></th>
                        <th><span class="sort-link <?= $sort==='name' ? 'active' : '' ?>" data-sort="name">Nombre <span class="sort-arrow"><?= $sort==='name' ? ($order==='asc' ? '↑' : '↓') : '' ?></span></span></th>
                        <th><span class="sort-link <?= $sort==='size' ? 'active' : '' ?>" data-sort="size">Tamaño <span class="sort-arrow"><?= $sort==='size' ? ($order==='asc' ? '↑' : '↓') : '' ?></span></span></th>
                        <th><span class="sort-link <?= $sort==='modified' ? 'active' : '' ?>" data-sort="modified">Modificado <span class="sort-arrow"><?= $sort==='modified' ? ($order==='asc' ? '↑' : '↓') : '' ?></span></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dirsList as $dir): ?>
                        <tr class="dir-row">
                            <td><input type="checkbox" disabled></td>
                            <td><a href="?dir=<?= $dirParam === '' ? urlencode($dir['name']) : urlencode($dirParam . '/' . $dir['name']) ?>&sort=<?= urlencode($sort) ?>&order=<?= urlencode($order) ?>" class="dir-link">📂 <?= htmlspecialchars($dir['name']) ?>/</a></td>
                            <td>-</td>
                            <td><?= htmlspecialchars($dir['mod']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <?php foreach ($filesMap as $name => $info): ?>
                        <tr class="file-row" data-name="<?= htmlspecialchars($info['name'], ENT_QUOTES) ?>" data-size="<?= $info['size_bytes'] ?>" data-mod="<?= htmlspecialchars($info['modified'], ENT_QUOTES) ?>">
                            <td><input type="checkbox" class="file-checkbox" name="files[]" value="<?= htmlspecialchars($name) ?>" <?= (!$info['selectable']) ? 'disabled' : '' ?>></td>
                            <td><a href="?dl=<?= urlencode($name) ?>&dir=<?= urlencode($dirParam) ?>&sort=<?= urlencode($sort) ?>&order=<?= urlencode($order) ?>" class="file-link">📄 <?= htmlspecialchars($info['name']) ?></a></td>
                            <td><?= $info['size'] ?></td>
                            <td><?= $info['modified'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </form>

        <div class="footer-info">MyFileLister v<?= htmlspecialchars($appVersion) ?></div>
    </div>

    <script>
        const selectAll = document.getElementById('select-all');
        const checkboxes = document.querySelectorAll('.file-checkbox');
        const downloadBtn = document.getElementById('downloadBtn');
        const counter = document.getElementById('counter');
        const searchInput = document.getElementById('searchInput');
        const clearSearchBtn = document.getElementById('clearSearch');
        const allRows = document.querySelectorAll('tbody tr');
        const form = document.getElementById('fileForm');
        const tableBody = document.querySelector('tbody');
        const sortLinks = document.querySelectorAll('.sort-link');
        const themeToggle = document.getElementById('themeToggle');

        // Estado inicial de ordenación
        let currentSort = '<?= $sort ?>';
        let currentOrder = '<?= $order ?>';

        // =========================================================
        // GESTIÓN DE TEMA (OSCuro/CLARO)
        // =========================================================
        function applyTheme(isLight) {
            if (isLight) {
                document.body.classList.add('light-theme');
                themeToggle.textContent = '🌙 Modo oscuro';
                localStorage.setItem('theme', 'light');
            } else {
                document.body.classList.remove('light-theme');
                themeToggle.textContent = '☀️ Modo claro';
                localStorage.setItem('theme', 'dark');
            }
        }

        themeToggle.addEventListener('click', () => {
            const isLight = document.body.classList.contains('light-theme');
            applyTheme(!isLight);
        });

        // Cargar preferencia al inicio
        (function() {
            const savedTheme = localStorage.getItem('theme') || 'dark';
            applyTheme(savedTheme === 'light');
        })();

        // =========================================================
        // ORDENACIÓN CLIENTE (sin recargar → mantiene selecciones)
        // =========================================================
        sortLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const sortType = this.dataset.sort;

                if (currentSort === sortType) {
                    currentOrder = currentOrder === 'asc' ? 'desc' : 'asc';
                } else {
                    currentSort = sortType;
                    currentOrder = 'asc';
                }

                sortTable();
                updateSortIndicators();
                updateURL();
            });
        });

        function sortTable() {
            const dirRows = Array.from(tableBody.querySelectorAll('.dir-row'));
            const fileRows = Array.from(tableBody.querySelectorAll('.file-row'));

            fileRows.sort((a, b) => {
                let valA, valB;

                if (currentSort === 'name') {
                    valA = a.dataset.name;
                    valB = b.dataset.name;
                    const cmp = valA.localeCompare(valB, undefined, { numeric: true, sensitivity: 'base' });
                    return currentOrder === 'asc' ? cmp : -cmp;
                } else if (currentSort === 'size') {
                    valA = parseFloat(a.dataset.size) || 0;
                    valB = parseFloat(b.dataset.size) || 0;
                    return currentOrder === 'asc' ? valA - valB : valB - valA;
                } else {
                    valA = a.dataset.mod;
                    valB = b.dataset.mod;
                    const cmp = valA.localeCompare(valB);
                    return currentOrder === 'asc' ? cmp : -cmp;
                }
            });

            dirRows.forEach(d => tableBody.appendChild(d));
            fileRows.forEach(r => tableBody.appendChild(r));
        }

        function updateSortIndicators() {
            sortLinks.forEach(link => {
                const sortType = link.dataset.sort;
                const arrow = link.querySelector('.sort-arrow');
                
                if (sortType === currentSort) {
                    link.classList.add('active');
                    arrow.textContent = currentOrder === 'asc' ? '↑' : '↓';
                } else {
                    link.classList.remove('active');
                    arrow.textContent = '';
                }
            });
        }

        function updateURL() {
            const params = new URLSearchParams(window.location.search);
            params.set('sort', currentSort);
            params.set('order', currentOrder);
            const newURL = window.location.pathname + '?' + params.toString();
            window.history.replaceState({}, '', newURL);
        }

        // =========================================================
        // BÚSQUEDA / FILTRO
        // =========================================================
        searchInput.addEventListener('input', filterTable);
        clearSearchBtn.addEventListener('click', () => { searchInput.value = ''; filterTable(); });

        function filterTable() {
            const term = searchInput.value.toLowerCase().trim();
            clearSearchBtn.classList.toggle('show', term.length > 0);
            let hasVisibleFiles = false;
            allRows.forEach(row => {
                const isVisible = row.textContent.toLowerCase().includes(term);
                row.style.display = isVisible ? '' : 'none';
                if (isVisible && row.classList.contains('file-row')) hasVisibleFiles = true;
            });
            selectAll.disabled = !hasVisibleFiles;
            updateUI();
        }

        // =========================================================
        // SELECCIÓN DE ARCHIVOS
        // =========================================================
        function updateUI() {
            const visibleCheckboxes = Array.from(checkboxes).filter(cb => cb.closest('tr').style.display !== 'none');
            const selectableCheckboxes = visibleCheckboxes.filter(cb => !cb.disabled);
            
            const count = selectableCheckboxes.filter(cb => cb.checked).length;
            
            downloadBtn.disabled = count === 0;
            counter.textContent = count === 0 ? '0 seleccionados' : count + ' seleccionado' + (count !== 1 ? 's' : '');
            
            const allSelectableChecked = selectableCheckboxes.length > 0 && selectableCheckboxes.every(cb => cb.checked);
            const someSelectableChecked = selectableCheckboxes.length > 0 && selectableCheckboxes.some(cb => cb.checked);
            
            selectAll.checked = allSelectableChecked;
            selectAll.indeterminate = someSelectableChecked && !allSelectableChecked;
        }

        selectAll.addEventListener('change', () => {
            checkboxes.forEach(cb => {
                if (cb.closest('tr').style.display !== 'none' && !cb.disabled) {
                    cb.checked = selectAll.checked;
                }
            });
            updateUI();
        });

        checkboxes.forEach(cb => cb.addEventListener('change', updateUI));
        downloadBtn.addEventListener('click', () => { if (!downloadBtn.disabled) form.submit(); });
        updateUI();
        document.getElementById('error-msg')?.classList.remove('show');
    </script>
</body>
</html>

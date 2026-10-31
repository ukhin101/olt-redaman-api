<?php
/**
 * ============================================================================
 * OLT Redaman API (HSGQ & HIOSO) - Interactive Testing Dashboard & Docs
 * ============================================================================
 * URL: http://localhost/olt-redaman-api/
 */

require_once __DIR__ . '/autoload.php';

use OltRedaman\Config\EnvLoader;

$safeConfig = EnvLoader::getSafeConfigSummary();
$hasEnv     = $safeConfig['env_file_exists'];
$hasCurl    = extension_loaded('curl');
$hasSnmp    = extension_loaded('snmp');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OLT Redaman API — HSGQ &amp; HIOSO Optical Power Backend</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap');

        :root {
            --bg-main: #090d16;
            --bg-card: #111827;
            --bg-elevated: #1e293b;
            --border: rgba(255, 255, 255, 0.08);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --cyan: #06b6d4;
            --blue: #3b82f6;
            --green: #10b981;
            --yellow: #f59e0b;
            --orange: #f97316;
            --red: #ef4444;
            --purple: #8b5cf6;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 0;
            background-color: var(--bg-main);
            color: var(--text-main);
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            line-height: 1.5;
        }

        .navbar {
            background: rgba(17, 24, 39, 0.9);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border);
            padding: 16px 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 14px;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .brand-wrap {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .brand-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--cyan), var(--blue));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: #fff;
            box-shadow: 0 4px 15px rgba(6, 182, 212, 0.3);
        }

        .brand-title h1 {
            margin: 0;
            font-size: 17px;
            font-weight: 700;
            letter-spacing: -0.3px;
        }

        .brand-title p {
            margin: 0;
            font-size: 12px;
            color: var(--text-muted);
        }

        .env-pills {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .pill {
            font-size: 12px;
            padding: 5px 11px;
            border-radius: 999px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid transparent;
        }

        .pill-ok { background: rgba(16, 185, 129, 0.12); color: #34d399; border-color: rgba(16, 185, 129, 0.25); }
        .pill-warn { background: rgba(245, 158, 11, 0.12); color: #fbbf24; border-color: rgba(245, 158, 11, 0.25); }
        .pill-err { background: rgba(239, 68, 68, 0.12); color: #f87171; border-color: rgba(239, 68, 68, 0.25); }
        .pill-info { background: rgba(59, 130, 246, 0.12); color: #60a5fa; border-color: rgba(59, 130, 246, 0.25); }

        .container {
            max-width: 1380px;
            margin: 0 auto;
            padding: 24px 28px 60px;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(380px, 1fr));
            gap: 18px;
            margin-bottom: 22px;
        }

        .card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 20px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.25);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border);
        }

        .card-title {
            font-size: 15px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
        }

        .config-list {
            display: grid;
            grid-template-columns: 135px 1fr;
            row-gap: 7px;
            font-size: 13px;
            margin-bottom: 16px;
        }

        .config-list span.label { color: var(--text-muted); }
        .config-list span.val { font-family: 'JetBrains Mono', monospace; color: var(--text-main); font-weight: 500; }

        .btn-group {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn {
            border: 1px solid var(--border);
            background: var(--bg-elevated);
            color: var(--text-main);
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            transition: all 0.18s;
            text-decoration: none;
        }

        .btn:hover { filter: brightness(1.15); transform: translateY(-1px); }
        .btn:disabled { opacity: 0.55; cursor: not-allowed; transform: none; }
        .btn-cyan { background: linear-gradient(135deg, #0891b2, #06b6d4); border-color: transparent; color: #fff; }
        .btn-purple { background: linear-gradient(135deg, #7c3aed, #8b5cf6); border-color: transparent; color: #fff; }
        .btn-green { background: linear-gradient(135deg, #059669, #10b981); border-color: transparent; color: #fff; }

        .stats-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(175px, 1fr));
            gap: 14px;
            margin-bottom: 22px;
        }

        .stat-box {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 15px 18px;
        }

        .stat-box .stat-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: var(--text-muted);
            font-weight: 600;
        }

        .stat-box .stat-value {
            font-size: 24px;
            font-weight: 700;
            margin-top: 4px;
            font-family: 'JetBrains Mono', monospace;
        }

        .alert-box {
            padding: 15px 18px;
            border-radius: 10px;
            margin-bottom: 18px;
            font-size: 13.5px;
            display: none;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.35);
            color: #fca5a5;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.35);
            color: #6ee7b7;
        }

        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .input-control, .select-control {
            background: #0b1120;
            border: 1px solid var(--border);
            color: var(--text-main);
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 13px;
            outline: none;
        }

        .input-control:focus, .select-control:focus {
            border-color: var(--cyan);
        }

        .table-wrap {
            overflow-x: auto;
            border: 1px solid var(--border);
            border-radius: 10px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 13px;
            white-space: nowrap;
        }

        th {
            background: #0d1424;
            color: var(--text-muted);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 13px 16px;
            border-bottom: 1px solid var(--border);
        }

        td {
            padding: 12px 16px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
        }

        tr:hover td { background: rgba(255, 255, 255, 0.02); }

        .mono { font-family: 'JetBrains Mono', monospace; }
        .rx-excellent { color: #38bdf8; font-weight: 700; }
        .rx-good { color: #34d399; font-weight: 700; }
        .rx-fair { color: #fbbf24; font-weight: 700; }
        .rx-poor, .rx-critical { color: #f87171; font-weight: 700; }
        .rx-no_signal { color: #64748b; }

        pre.json-viewer {
            background: #070a12;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
            color: #a5f3fc;
            max-height: 440px;
            overflow: auto;
            margin: 0;
        }

        .endpoint-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 14px;
            background: #0b1120;
            border: 1px solid var(--border);
            border-radius: 8px;
            margin-bottom: 8px;
            font-size: 13px;
        }

        .method-badge {
            background: rgba(6, 182, 212, 0.15);
            color: #22d3ee;
            padding: 3px 8px;
            border-radius: 5px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 11px;
            font-weight: 700;
        }
    </style>
</head>
<body>

<nav class="navbar">
    <div class="brand-wrap">
        <div class="brand-icon"><i class="fas fa-wave-square"></i></div>
        <div class="brand-title">
            <h1>OLT Redaman API <span style="font-weight:400; color:var(--cyan);">v1.0</span></h1>
            <p>Standalone Optical Power Backend (HSGQ &amp; HIOSO) — Siap Integrasi Monitoring Map FTTH</p>
        </div>
    </div>
    <div class="env-pills">
        <?php if ($hasEnv): ?>
            <span class="pill pill-ok"><i class="fas fa-check-circle"></i> .env Aktif</span>
        <?php else: ?>
            <span class="pill pill-warn"><i class="fas fa-exclamation-triangle"></i> .env Belum Dibuat (Jalankan install.bat)</span>
        <?php endif; ?>

        <span class="pill <?= $hasCurl ? 'pill-ok' : 'pill-err' ?>">
            <i class="fas fa-globe"></i> cURL: <?= $hasCurl ? 'Aktif' : 'Nonaktif' ?>
        </span>
        <span class="pill <?= $hasSnmp ? 'pill-ok' : 'pill-info' ?>">
            <i class="fas fa-network-wired"></i> SNMP: <?= $hasSnmp ? 'Aktif' : 'Fallback Mode' ?>
        </span>
    </div>
</nav>

<div class="container">

    <!-- Panel Konfigurasi & Kontrol Masing-Masing OLT -->
    <div class="grid-2">
        <!-- Kartu OLT HSGQ -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-server" style="color:var(--cyan);"></i> Modul Backend OLT HSGQ</h2>
                <span class="pill <?= $safeConfig['hsgq']['enabled'] ? 'pill-info' : 'pill-warn' ?>">
                    <?= htmlspecialchars($safeConfig['hsgq']['type']) ?> • <?= (int)$safeConfig['hsgq']['pon_ports'] ?> PON
                </span>
            </div>
            <div class="config-list">
                <span class="label">Nama OLT</span>
                <span class="val"><?= htmlspecialchars($safeConfig['hsgq']['name']) ?></span>
                <span class="label">Host &amp; Port</span>
                <span class="val"><?= htmlspecialchars($safeConfig['hsgq']['host'] . ':' . $safeConfig['hsgq']['port']) ?></span>
                <span class="label">Metode Akses</span>
                <span class="val"><?= strtoupper(htmlspecialchars($safeConfig['hsgq']['method'])) ?> (Web JSON API / CLI)</span>
                <span class="label">Username / Pass</span>
                <span class="val"><?= htmlspecialchars($safeConfig['hsgq']['username'] . ' / ' . $safeConfig['hsgq']['password']) ?></span>
            </div>
            <div class="btn-group">
                <button class="btn btn-cyan" onclick="fetchOltData('hsgq', false)">
                    <i class="fas fa-bolt"></i> Ambil Redaman HSGQ
                </button>
                <button class="btn" onclick="testOltConnection('hsgq')">
                    <i class="fas fa-plug"></i> Tes Koneksi HSGQ
                </button>
                <a class="btn" href="api/hsgq.php" target="_blank">
                    <i class="fas fa-external-link-alt"></i> JSON Endpoint
                </a>
            </div>
        </div>

        <!-- Kartu OLT HIOSO -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-microchip" style="color:var(--purple);"></i> Modul Backend OLT HIOSO</h2>
                <span class="pill <?= $safeConfig['hioso']['enabled'] ? 'pill-info' : 'pill-warn' ?>">
                    <?= htmlspecialchars($safeConfig['hioso']['type']) ?> • <?= (int)$safeConfig['hioso']['pon_ports'] ?> PON
                </span>
            </div>
            <div class="config-list">
                <span class="label">Nama OLT</span>
                <span class="val"><?= htmlspecialchars($safeConfig['hioso']['name']) ?></span>
                <span class="label">Host / IP</span>
                <span class="val"><?= htmlspecialchars($safeConfig['hioso']['host']) ?> (SNMP: <?= (int)$safeConfig['hioso']['snmp_port'] ?>, HTTP: <?= (int)$safeConfig['hioso']['http_port'] ?>)</span>
                <span class="label">Metode Akses</span>
                <span class="val"><?= strtoupper(htmlspecialchars($safeConfig['hioso']['method'])) ?> (Profil: <?= htmlspecialchars($safeConfig['hioso']['snmp_profile']) ?>)</span>
                <span class="label">SNMP / Web Auth</span>
                <span class="val">Comm: <?= htmlspecialchars($safeConfig['hioso']['snmp_community']) ?> | User: <?= htmlspecialchars($safeConfig['hioso']['username']) ?></span>
            </div>
            <div class="btn-group">
                <button class="btn btn-purple" onclick="fetchOltData('hioso', false)">
                    <i class="fas fa-bolt"></i> Ambil Redaman HIOSO
                </button>
                <button class="btn" onclick="testOltConnection('hioso')">
                    <i class="fas fa-plug"></i> Tes Koneksi HIOSO
                </button>
                <a class="btn" href="api/hioso.php" target="_blank">
                    <i class="fas fa-external-link-alt"></i> JSON Endpoint
                </a>
            </div>
        </div>
    </div>

    <!-- Bar Statistik Ringkasan -->
    <div class="stats-bar">
        <div class="stat-box">
            <div class="stat-label">Total ONU Terbaca</div>
            <div class="stat-value" id="statTotal">0</div>
        </div>
        <div class="stat-box">
            <div class="stat-label">ONU Online</div>
            <div class="stat-value" style="color:var(--green);" id="statOnline">0</div>
        </div>
        <div class="stat-box">
            <div class="stat-label">ONU Offline</div>
            <div class="stat-value" style="color:var(--red);" id="statOffline">0</div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Rata-rata Rx Power</div>
            <div class="stat-value" style="color:var(--cyan);" id="statAvgRx">—</div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Redaman Terlemah</div>
            <div class="stat-value" style="color:var(--orange);" id="statWeakestRx">—</div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Waktu Eksekusi</div>
            <div class="stat-value" style="font-size:18px;" id="statTime">—</div>
        </div>
    </div>

    <!-- Kotak Pesan Diagnosa / Error / Validasi -->
    <div id="alertBox" class="alert-box"></div>

    <!-- Panel Tabel Data Redaman & Filter -->
    <div class="card" style="margin-bottom: 22px;">
        <div class="toolbar">
            <div class="btn-group">
                <button class="btn btn-green" onclick="fetchOltData('all', false)">
                    <i class="fas fa-layer-group"></i> Ambil Gabungan (HSGQ + HIOSO)
                </button>
                <button class="btn" onclick="fetchOltData(currentEndpointMode, true)">
                    <i class="fas fa-sync-alt"></i> Refresh Real-Time (Bypass Cache)
                </button>
                <button class="btn" onclick="testOltConnection('all')">
                    <i class="fas fa-stethoscope"></i> Diagnosa Semua OLT
                </button>
            </div>

            <div class="btn-group">
                <input type="text" id="searchInput" class="input-control" placeholder="Cari Nama ONU, SN/MAC, Port..." oninput="renderFilteredTable()">
                <select id="statusFilter" class="select-control" onchange="renderFilteredTable()">
                    <option value="all">Semua Status</option>
                    <option value="online">Hanya Online</option>
                    <option value="offline">Hanya Offline</option>
                    <option value="weak">Sinyal Lemah (&lt; -26 dBm)</option>
                </select>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Merek / OLT</th>
                        <th>Port PON</th>
                        <th>ID ONU</th>
                        <th>Nama ONU</th>
                        <th>SN / MAC ONU</th>
                        <th>Status</th>
                        <th>Rx Power (ONU)</th>
                        <th>Tx Power (ONU)</th>
                        <th>Kualitas Sinyal</th>
                        <th>Alasan Down / Jarak</th>
                        <th>Waktu Ambil</th>
                    </tr>
                </thead>
                <tbody id="onuTableBody">
                    <tr>
                        <td colspan="11" style="text-align:center; padding:32px; color:var(--text-muted);">
                            Klik tombol <strong>"Ambil Redaman HSGQ"</strong>, <strong>"Ambil Redaman HIOSO"</strong>, atau <strong>"Ambil Gabungan"</strong> di atas untuk memuat data.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Panel Daftar Endpoint & Live JSON Output -->
    <div class="grid-2">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-code" style="color:var(--cyan);"></i> Daftar Endpoint REST API Siap Pakai</h3>
            </div>
            <div class="endpoint-row">
                <div><span class="method-badge">GET</span> <code class="mono">/olt-redaman-api/api/hsgq.php</code></div>
                <span style="color:var(--text-muted); font-size:12px;">Khusus OLT HSGQ</span>
            </div>
            <div class="endpoint-row">
                <div><span class="method-badge">GET</span> <code class="mono">/olt-redaman-api/api/hioso.php</code></div>
                <span style="color:var(--text-muted); font-size:12px;">Khusus OLT HIOSO</span>
            </div>
            <div class="endpoint-row">
                <div><span class="method-badge">GET</span> <code class="mono">/olt-redaman-api/api/all.php</code></div>
                <span style="color:var(--text-muted); font-size:12px;">Gabungan HSGQ + HIOSO</span>
            </div>
            <div class="endpoint-row">
                <div><span class="method-badge">GET</span> <code class="mono">/olt-redaman-api/api/all.php?onu={SN/Nama}</code></div>
                <span style="color:var(--text-muted); font-size:12px;">Cari 1 ONU Spesifik</span>
            </div>
            <div class="endpoint-row">
                <div><span class="method-badge">GET</span> <code class="mono">/olt-redaman-api/api/test.php?brand=all</code></div>
                <span style="color:var(--text-muted); font-size:12px;">Tes Koneksi &amp; Validasi</span>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-terminal" style="color:var(--green);"></i> Live Response JSON Inspector</h3>
                <span id="jsonEndpointLabel" class="mono" style="font-size:11px; color:var(--text-muted);">Belum ada request</span>
            </div>
            <pre id="jsonViewer" class="json-viewer">// Respons mentah JSON dari endpoint API akan tampil di sini...</pre>
        </div>
    </div>

</div>

<script>
let currentOnuList = [];
let currentEndpointMode = 'all';

function showAlert(type, htmlContent) {
    const box = document.getElementById('alertBox');
    box.className = 'alert-box ' + (type === 'error' ? 'alert-error' : 'alert-success');
    box.innerHTML = htmlContent;
    box.style.display = 'block';
}

async function fetchOltData(mode = 'all', refresh = false) {
    currentEndpointMode = mode;
    const mapEndpoint = {
        hsgq: 'api/hsgq.php',
        hioso: 'api/hioso.php',
        all: 'api/all.php'
    };
    const url = (mapEndpoint[mode] || 'api/all.php') + (refresh ? '?refresh=1' : '');
    document.getElementById('jsonEndpointLabel').textContent = 'GET ' + url;

    const tbody = document.getElementById('onuTableBody');
    tbody.innerHTML = `<tr><td colspan="11" style="text-align:center; padding:32px; color:var(--cyan);">
        <i class="fas fa-spinner fa-spin"></i> Sedang menghubungi perangkat OLT (${mode.toUpperCase()})...
    </td></tr>`;

    try {
        const res = await fetch(url);
        const payload = await res.json();
        document.getElementById('jsonViewer').textContent = JSON.stringify(payload, null, 2);

        currentOnuList = Array.isArray(payload.data) ? payload.data : [];
        updateStats(payload);
        renderFilteredTable();

        if (!payload.success) {
            let errHtml = `<strong><i class="fas fa-triangle-exclamation"></i> Pengambilan Data OLT Gagal / Tidak Dapat Diakses:</strong><br>`;
            if (payload.error) {
                errHtml += `<div style="margin-top:4px;">• ${escapeHtml(payload.error)}</div>`;
            }
            if (payload.errors) {
                Object.keys(payload.errors).forEach(k => {
                    errHtml += `<div style="margin-top:4px;">• <strong>${escapeHtml(k)}:</strong> ${escapeHtml(payload.errors[k])}</div>`;
                });
            }
            if (Array.isArray(payload.troubleshooting)) {
                errHtml += `<div style="margin-top:8px; font-size:12.5px; color:#cbd5e1;"><strong>Saran Perbaikan:</strong><br>` +
                    payload.troubleshooting.map(t => `- ${escapeHtml(t)}`).join('<br>') + `</div>`;
            }
            showAlert('error', errHtml);
        } else if (payload.partial_success && payload.errors) {
            let warnHtml = `<strong><i class="fas fa-circle-info"></i> Sebagian OLT Berhasil Diakses (Partial Success):</strong><br>`;
            Object.keys(payload.errors).forEach(k => {
                warnHtml += `<div>• <strong>${escapeHtml(k)}:</strong> ${escapeHtml(payload.errors[k])}</div>`;
            });
            showAlert('error', warnHtml);
        } else {
            const valMsg = payload.validation?.validation_message || 'Data berhasil diambil.';
            showAlert('success', `<i class="fas fa-check-circle"></i> <strong>Berhasil (${mode.toUpperCase()}):</strong> ${escapeHtml(valMsg)}`);
        }
    } catch (err) {
        showAlert('error', `<strong>Gagal memanggil API:</strong> ${escapeHtml(err.message)}`);
    }
}

async function testOltConnection(brand = 'all') {
    const url = `api/test.php?brand=${encodeURIComponent(brand)}&validate=1`;
    document.getElementById('jsonEndpointLabel').textContent = 'GET ' + url;
    showAlert('success', `<i class="fas fa-spinner fa-spin"></i> Sedang menguji koneksi dan memvalidasi pembacaan redaman OLT (${brand.toUpperCase()})...`);

    try {
        const res = await fetch(url);
        const payload = await res.json();
        document.getElementById('jsonViewer').textContent = JSON.stringify(payload, null, 2);

        let lines = [];
        const results = payload.results || {};
        Object.keys(results).forEach(k => {
            const r = results[k];
            const badge = r.success ? '✅ TERHUBUNG' : '❌ TIDAK DAPAT DIAKSES';
            lines.push(`<div style="margin-bottom:4px;"><strong>[${k.toUpperCase()} - ${escapeHtml(r.olt_name || '')}] ${badge}:</strong> ${escapeHtml(r.message || '')}</div>`);
            if (r.steps) {
                const st = r.steps;
                const icon = s => s === 'ok' ? '🟢' : (s === 'failed' ? '🔴' : '⚪');
                lines.push(`<div style="margin-left:16px; font-size:12px; color:#cbd5e1;">` +
                    `${icon(st.ip_reachability?.status)} IP: ${escapeHtml(st.ip_reachability?.detail || '-')} &nbsp;|&nbsp; ` +
                    `${icon(st.port_access?.status)} Port: ${escapeHtml(st.port_access?.detail || '-')} &nbsp;|&nbsp; ` +
                    `${icon(st.authentication?.status)} Auth: ${escapeHtml(st.authentication?.detail || '-')} &nbsp;|&nbsp; ` +
                    `${icon(st.onu_data_fetch?.status)} Data ONU: ${escapeHtml(st.onu_data_fetch?.detail || '-')}` +
                    `</div>`);
            }
            if (r.reading_validation) {
                lines.push(`<div style="margin-left:16px; font-size:12.5px; margin-top:2px;">↳ ${escapeHtml(r.reading_validation.validation_message || '')} (Tx terbaca: ${r.reading_validation.valid_tx_readings ?? 0} ONU)</div>`);
            }
            if (!r.success && r.failure_cause?.cause) {
                lines.push(`<div style="margin-left:16px; font-size:12px; color:#fca5a5;">↳ Penyebab Spesifik: ${escapeHtml(r.failure_cause.cause)}</div>`);
            }
            if (!r.success && r.diagnostics && Array.isArray(r.diagnostics.hints)) {
                r.diagnostics.hints.forEach(h => {
                    lines.push(`<div style="margin-left:16px; font-size:12px; color:#cbd5e1;">• ${escapeHtml(h)}</div>`);
                });
            }
        });

        showAlert(payload.success ? 'success' : 'error', lines.join(''));
    } catch (err) {
        showAlert('error', `Gagal menjalankan tes koneksi: ${escapeHtml(err.message)}`);
    }
}

function updateStats(payload) {
    const s = payload.summary || {};
    const rx = s.rx_power_stats || {};
    document.getElementById('statTotal').textContent = s.total_onu ?? 0;
    document.getElementById('statOnline').textContent = s.online_count ?? 0;
    document.getElementById('statOffline').textContent = s.offline_count ?? 0;
    document.getElementById('statAvgRx').textContent = rx.average_dbm !== null && rx.average_dbm !== undefined ? `${rx.average_dbm} dBm` : '—';
    document.getElementById('statWeakestRx').textContent = rx.weakest_dbm !== null && rx.weakest_dbm !== undefined ? `${rx.weakest_dbm} dBm` : '—';
    document.getElementById('statTime').textContent = payload.execution_time_ms !== undefined ? `${payload.execution_time_ms} ms` : '—';
}

function renderFilteredTable() {
    const tbody = document.getElementById('onuTableBody');
    const q = (document.getElementById('searchInput').value || '').toLowerCase().trim();
    const st = document.getElementById('statusFilter').value;

    const filtered = currentOnuList.filter(item => {
        if (st === 'online' && !item.is_online) return false;
        if (st === 'offline' && item.is_online) return false;
        if (st === 'weak' && (item.rx_power === null || item.rx_power > -26.0)) return false;

        if (q !== '') {
            const hay = `${item.olt_brand} ${item.olt_name} ${item.pon_label} ${item.onu_id} ${item.onu_name} ${item.sn} ${item.model || ''}`.toLowerCase();
            if (!hay.includes(q)) return false;
        }
        return true;
    });

    if (filtered.length === 0) {
        tbody.innerHTML = `<tr><td colspan="11" style="text-align:center; padding:28px; color:var(--text-muted);">
            Tidak ada data ONU yang sesuai atau OLT belum mengembalikan data.
        </td></tr>`;
        return;
    }

    tbody.innerHTML = filtered.map(u => {
        const statusPill = u.is_online
            ? `<span class="pill pill-ok"><i class="fas fa-circle" style="font-size:7px;"></i> Online</span>`
            : `<span class="pill pill-err"><i class="fas fa-circle" style="font-size:7px;"></i> Offline</span>`;

        const rxClass = 'rx-' + (u.signal_quality || 'no_signal');
        const rxText = u.rx_power_formatted || '—';
        const txText = u.tx_power_formatted || '—';
        const extra = u.last_down_reason
            ? escapeHtml(u.last_down_reason)
            : (u.distance_m ? `${u.distance_m} m` : '—');

        return `<tr>
            <td><strong>${escapeHtml(u.olt_brand)}</strong> <span style="color:var(--text-muted); font-size:12px;">(${escapeHtml(u.olt_name)})</span></td>
            <td class="mono">${escapeHtml(u.pon_label)}</td>
            <td class="mono">${escapeHtml(u.onu_id)}</td>
            <td><strong>${escapeHtml(u.onu_name)}</strong></td>
            <td class="mono">${escapeHtml(u.sn)}</td>
            <td>${statusPill}</td>
            <td class="mono ${rxClass}">${escapeHtml(rxText)}</td>
            <td class="mono">${escapeHtml(txText)}</td>
            <td style="font-size:12px;">${escapeHtml(u.signal_label || '—')}</td>
            <td style="font-size:12px; color:var(--text-muted);">${extra}</td>
            <td class="mono" style="font-size:11.5px; color:var(--text-muted);">${escapeHtml((u.fetched_at || '').replace('T', ' ').slice(0, 19))}</td>
        </tr>`;
    }).join('');
}

function escapeHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
</script>

</body>
</html>


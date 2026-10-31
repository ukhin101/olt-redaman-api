# 📡 OLT Redaman API (`olt-redaman-api`) — HSGQ & HIOSO Optical Power Backend

**`olt-redaman-api`** adalah proyek backend dan REST API mandiri (*standalone*) yang dirancang khusus untuk mengambil data **redaman optik (Rx Power / Tx Power / Optical Power)** ONT/ONU dari dua perangkat OLT yang paling banyak digunakan pada jaringan FTTH / RT-RW Net, yaitu **HSGQ** dan **HIOSO**.

Proyek ini disusun dengan arsitektur **modular** dan **read-only** (tidak pernah mengubah konfigurasi OLT yang sedang berjalan), sehingga dapat digunakan sebagai dokumentasi teknis sekaligus modul backend siap pakai (*plug-and-play*) pada proyek **Monitoring Map FTTH** sekarang maupun di masa mendatang.

---

## ✨ Fitur Utama

1. **Arsitektur Driver Terpisah (`HsgqDriver` & `HiosoDriver`)**:
   - Modul pembacaan OLT **HSGQ** dan **HIOSO** dipisahkan ke dalam class tersendiri (`src/Drivers/HsgqDriver.php` dan `src/Drivers/HiosoDriver.php`) yang mengimplementasikan kontrak standar `OltDriverInterface`.
   - Pengembangan atau perbaikan pada satu merek OLT tidak akan mengganggu modul OLT lainnya.
2. **Protokol Komunikasi Nyata (Tanpa Endpoint Karangan)**:
   - **OLT HSGQ (EPON / GPON)**:
     - **HTTP Web JSON API (Utama)**: Autentikasi sesi otomatis melalui `userlogin?form=login` (MD5 + Base64 + header dinamis `X-Token`), fallback `login.cgi`, serta fallback REST `api/login`. Mengambil daftar ONU dan optik dari endpoint asli firmware HSGQ (`onu_list`, `onu_allow_list`, `onu_optical_info`, `onu_info`, serta `gponont_mgmt` untuk varian GPON).
     - **Telnet CLI Read-Only (Alternatif)**: Mengeksekusi perintah baca `show onu-info all` dan `show onu optical-info <port>`.
   - **OLT HIOSO (HA7302CS / HA7304 / HA7308 — EPON & GPON)**:
     - **SNMP v2c Read-Only (Utama & Tercepat)**: Mendukung deteksi otomatis berbagai cabang Private MIB Hioso:
       - `HIOSO_C` (Enterprise `.1.3.6.1.4.1.25355.3.2` — C-Data/Hioso EPON)
       - `HIOSO_GPON` (Enterprise `.1.3.6.1.4.1.25355.3.3` — C-Data/Hioso GPON)
       - `HIOSO_B` (Enterprise `.1.3.6.1.4.1.3320.101` — Hioso/BDCOM EPON)
       - `HIOSO_STD` (Enterprise `.1.3.6.1.4.1.17409.2.3` — Standard EPON MIB)
     - **HTTP Web Management ASP (Alternatif 1)**: Membaca halaman `.asp` bawaan Hioso (`onuConfigPonList.asp`, `onuOpticalList.asp`, `ponOnuList.asp`) menggunakan Basic Auth / `/goform/login`.
     - **Telnet CLI Read-Only (Alternatif 2)**: Dilengkapi negosiasi byte Telnet IAC (`0xFF`) penuh dan penanganan paginasi `--More--` untuk menjalankan `show onu info` & `show onu optical-ddm`.
3. **Normalisasi & Kategorisasi Redaman Standar**:
   - Mengonversi seluruh format sinyal mentah (string desimal, integer bertanda 16-bit HSGQ, maupun integer berskala Hioso) menjadi satuan standar **`dBm`** (`float`).
   - Mengelompokkan kualitas redaman secara otomatis: `excellent` (`> -18 dBm`), `good` (`-18 s/d -23 dBm`), `fair` (`-23 s/d -26 dBm`), `poor` (`-26 s/d -27 dBm`), `critical` (`< -27 dBm`), dan `no_signal`.
4. **Sistem Cache Pintar**:
   - Dilengkapi file-based cache (`storage/cache/`) dengan durasi TTL yang dapat diatur di `.env` (default 30 detik) agar polling peta FTTH tidak membebani CPU OLT.
5. **Keamanan Kredensial (`.env` & `.gitignore`)**:
   - Seluruh alamat IP, port, username, password, dan SNMP community disimpan di `.env` yang dikecualikan dari GitHub.
6. **SDK Integrasi Siap Pakai (PHP & JavaScript/Node.js)**:
   - Menyediakan `sdk/OltRedamanClient.php` dan `sdk/olt-redaman-client.js` untuk menghubungkan aplikasi **Monitoring Map FTTH** hanya dengan beberapa baris kode.

---

## 📁 Struktur Direktori Proyek

Lokasi penyimpanan: `C:\xampp\htdocs\olt-redaman-api`

```text
C:\xampp\htdocs\olt-redaman-api\
│
├── api\                                  # Endpoint HTTP REST API
│   ├── index.php                         # Informasi layanan & daftar seluruh endpoint API
│   ├── hsgq.php                          # Endpoint khusus OLT HSGQ (data redaman & tes koneksi)
│   ├── hioso.php                         # Endpoint khusus OLT HIOSO (data redaman & tes koneksi)
│   ├── all.php                           # Endpoint gabungan (HSGQ + HIOSO dalam format seragam)
│   └── test.php                          # Endpoint pengujian koneksi & validasi pembacaan redaman
│
├── src\                                  # Modul Inti Backend (PSR-4: OltRedaman\*)
│   ├── Config\
│   │   └── EnvLoader.php                 # Pembaca file .env mandiri (tanpa wajib Composer)
│   ├── Contracts\
│   │   └── OltDriverInterface.php        # Kontrak interface standar driver OLT
│   ├── Drivers\
│   │   ├── HsgqDriver.php                # Backend khusus OLT HSGQ (HTTP JSON API + CGI + Telnet)
│   │   └── HiosoDriver.php               # Backend khusus OLT HIOSO (SNMP Multi-MIB + ASP + Telnet)
│   ├── Support\
│   │   ├── OnuDataNormalizer.php         # Normalisasi sinyal dBm, status, filter, & validasi
│   │   ├── CacheManager.php              # Manajemen file-cache pembacaan OLT
│   │   ├── Logger.php                    # Pencatat log koneksi & error (password di-masking)
│   │   └── ResponseHelper.php            # Helper JSON response, CORS, & proteksi API Key
│   └── OltRedamanManager.php             # Orchestrator utama untuk memanggil HSGQ, HIOSO, atau Gabungan
│
├── sdk\                                  # Modul Client Siap Pakai untuk Proyek Monitoring FTTH
│   ├── OltRedamanClient.php              # SDK Client untuk proyek berbasis PHP
│   └── olt-redaman-client.js             # SDK Client untuk proyek berbasis Node.js / Browser JS
│
├── cli\                                  # Utilitas Command Line (CLI)
│   └── test-olt.php                      # Script CLI untuk tes koneksi, self-test, & validasi redaman
│
├── storage\                              # Direktori runtime lokal (diabaikan oleh Git)
│   ├── cache\                            # Penyimpanan cache JSON & cookie sesi sementara
│   └── logs\                             # Log harian koneksi & error OLT
│
├── .env.example                          # Template konfigurasi OLT (aman untuk GitHub)
├── .gitignore                            # Proteksi agar .env, cookie, cache, & log tidak ter-upload ke GitHub
├── .htaccess                             # Konfigurasi Apache XAMPP (CORS & proteksi file sensitif)
├── autoload.php                          # PSR-4 Autoloader mandiri (langsung jalan di XAMPP)
├── composer.json                         # Definisi paket & metadata proyek PHP
├── index.php                             # Dashboard Web Interaktif untuk Pengujian & Dokumentasi Live
├── install.bat                           # Installer 1-klik untuk komputer Windows dengan XAMPP
├── test-connection.bat                   # Shortcut 1-klik untuk menguji koneksi OLT via CLI
├── push-github.bat                       # Script bantuan untuk push repository ke GitHub
└── README.md                             # Dokumentasi lengkap proyek ini
```

---

## 💻 Persyaratan Sistem

- **Sistem Operasi**: Windows 10 / 11 / Windows Server (atau Linux/macOS)
- **Web Server**: Apache (melalui **XAMPP for Windows** di `C:\xampp`)
- **Versi PHP**: PHP 7.4, 8.0, 8.1, atau 8.2+
- **Ekstensi PHP**:
  - `ext-curl` (Wajib — sudah aktif secara bawaan di XAMPP)
  - `ext-json` (Wajib — sudah aktif secara bawaan di XAMPP)
  - `ext-snmp` (Direkomendasikan untuk OLT HIOSO via SNMP; jika tidak aktif, sistem otomatis menggunakan fallback CLI `snmpwalk`, HTTP Web ASP, atau Telnet).

---

## 🚀 Cara Instalasi & Instalasi Ulang di Windows XAMPP

Jika Anda memindahkan proyek ini ke komputer Windows baru yang menggunakan XAMPP:

### Cara 1: Menggunakan Installer Otomatis (`install.bat`)
1. Clone atau salin folder proyek ke `C:\xampp\htdocs\olt-redaman-api`:
   ```bash
   cd C:\xampp\htdocs
   git clone https://github.com/ukhin101/olt-redaman-api.git
   cd olt-redaman-api
   ```
2. Klik dua kali file **`install.bat`** (atau jalankan `.\install.bat` di terminal).
   Installer akan otomatis:
   - Memeriksa keberadaan `C:\xampp\php\php.exe` dan ekstensi PHP.
   - Membuat direktori `storage\cache` dan `storage\logs`.
   - Menyalin `.env.example` menjadi `.env` (jika `.env` belum ada).
   - Menjalankan validasi internal (*self-test*) pembacaan sinyal optik.

### Cara 2: Instalasi Manual
1. Salin file `.env.example` menjadi `.env`:
   ```powershell
   copy .env.example .env
   ```
2. Buka file `.env` dengan teks editor dan sesuaikan alamat IP serta kredensial OLT Anda.
3. Nyalakan modul **Apache** pada **XAMPP Control Panel**.
4. Akses dashboard di browser: **`http://localhost/olt-redaman-api/`**

---

## ⚙️ Konfigurasi File `.env`

Seluruh pengaturan koneksi OLT dikendalikan melalui file `.env`:

```ini
# --- PENGATURAN UMUM ---
APP_TIMEZONE=Asia/Jakarta
API_KEY=
CACHE_ENABLED=true
CACHE_TTL_SECONDS=30

# --- 1. KONFIGURASI OLT HSGQ ---
HSGQ_ENABLED=true
HSGQ_NAME="OLT-HSGQ-01"
HSGQ_HOST=192.168.1.10
HSGQ_PORT=80
HSGQ_SCHEME=http
HSGQ_USERNAME=your_hsgq_username
HSGQ_PASSWORD=your_hsgq_password_here
HSGQ_METHOD=http
HSGQ_TYPE=EPON
HSGQ_PON_PORTS=8
HSGQ_TIMEOUT=15

# --- 2. KONFIGURASI OLT HIOSO ---
HIOSO_ENABLED=true
HIOSO_NAME="OLT-HIOSO-01"
HIOSO_HOST=192.168.1.20
HIOSO_METHOD=auto
HIOSO_SNMP_PORT=161
HIOSO_SNMP_COMMUNITY=public
HIOSO_SNMP_PROFILE=auto
HIOSO_HTTP_PORT=80
HIOSO_TELNET_PORT=23
HIOSO_USERNAME=your_hioso_username
HIOSO_PASSWORD=your_hioso_password_here
HIOSO_TYPE=EPON
HIOSO_PON_PORTS=4
HIOSO_TIMEOUT=15
```

---

## 🔌 Dokumentasi Endpoint REST API

### 1. Ambil Data Redaman OLT HSGQ
- **Endpoint**: `GET http://localhost/olt-redaman-api/api/hsgq.php`
- **Parameter Query Opsional**:
  - `?pon_port=1` — Hanya ambil ONU dari Port PON 1
  - `?status=online` — Filter hanya ONU `online` atau `offline`
  - `?sn=00:11:41` — Cari berdasarkan SN atau MAC Address
  - `?search=LITA` — Cari berdasarkan nama pelanggan / ONU
  - `?weak_only=1` — Hanya tampilkan ONU dengan redaman lemah (`< -26.0 dBm`)
  - `?refresh=1` — Abaikan cache dan tarik data langsung dari OLT

### 2. Ambil Data Redaman OLT HIOSO
- **Endpoint**: `GET http://localhost/olt-redaman-api/api/hioso.php`
- **Parameter Query Opsional**: Sama seperti di atas (`pon_port`, `status`, `sn`, `search`, `weak_only`, `refresh`).

### 3. Ambil Data Redaman Gabungan (HSGQ + HIOSO)
- **Endpoint**: `GET http://localhost/olt-redaman-api/api/all.php`
- **Pencarian Cepat 1 ONU di Kedua OLT**:
  - `GET http://localhost/olt-redaman-api/api/all.php?onu=00:11:41:17:D6:48`
  - `GET http://localhost/olt-redaman-api/api/all.php?onu=LITA`

### 4. Pengujian Koneksi & Validasi Pembacaan Redaman
- **Endpoint**:
  - Kedua OLT: `GET http://localhost/olt-redaman-api/api/test.php`
  - Khusus HSGQ: `GET http://localhost/olt-redaman-api/api/test.php?brand=hsgq`
  - Khusus HIOSO: `GET http://localhost/olt-redaman-api/api/test.php?brand=hioso`

---

## 📦 Contoh Respons JSON Standar

Setiap ONU yang dikembalikan oleh endpoint `hsgq.php`, `hioso.php`, maupun `all.php` memiliki struktur atribut yang **100% seragam**:

```json
{
  "success": true,
  "brand": "HSGQ",
  "olt_name": "OLT-HSGQ-01",
  "olt_host": "192.168.150.2:80",
  "olt_type": "EPON",
  "pon_ports_scanned": 8,
  "method_used": "http (userlogin_json)",
  "from_cache": false,
  "fetched_at": "2026-10-31T21:45:00+07:00",
  "execution_time_ms": 412.35,
  "summary": {
    "total_onu": 166,
    "online_count": 158,
    "offline_count": 8,
    "signal_distribution": {
      "excellent": 24,
      "good": 112,
      "fair": 18,
      "poor": 3,
      "critical": 1,
      "no_signal": 8
    },
    "rx_power_stats": {
      "readable_count": 158,
      "average_dbm": -21.45,
      "strongest_dbm": -15.2,
      "weakest_dbm": -27.85
    },
    "tx_power_stats": {
      "readable_count": 158,
      "average_dbm": 2.14
    }
  },
  "validation": {
    "is_valid": true,
    "total_records_read": 166,
    "valid_rx_readings": 158,
    "valid_tx_readings": 158,
    "critical_onu_count": 4,
    "warnings": [
      "Redaman kritis pada PELANGGAN-A (1/14 - 00:11:41:22:33:44): -27.85 dBm"
    ],
    "validation_message": "Validasi berhasil: 158 dari 158 ONU online memiliki pembacaan Rx Power optik yang valid."
  },
  "data": [
    {
      "olt_brand": "HSGQ",
      "olt_name": "OLT-HSGQ-01",
      "olt_host": "192.168.150.2",
      "onu_id": "1/1",
      "onu_index": "1/1",
      "pon_port": "1",
      "pon_label": "PON 1",
      "onu_number": 1,
      "onu_name": "LITA",
      "sn": "00:11:41:17:D6:48",
      "mac_address": "00:11:41:17:D6:48",
      "status": "online",
      "status_label": "Online",
      "is_online": true,
      "rx_power": -22.52,
      "rx_power_formatted": "-22.52 dBm",
      "tx_power": 2.1,
      "tx_power_formatted": "2.10 dBm",
      "olt_rx_power": null,
      "signal_quality": "good",
      "signal_label": "Good (-18 s/d -23 dBm)",
      "signal_color": "green",
      "distance_m": 2308,
      "temperature_c": 42.5,
      "model": "1ge3fe",
      "vendor": "YEKF",
      "last_down_reason": "Dying gasp",
      "last_down_time": "2026/10/25 16:22:36",
      "fetched_at": "2026-10-31T21:45:00+07:00"
    }
  ]
}
```

---

## 🔗 Panduan Penggunaan Ulang (Integrasi ke Monitoring Map FTTH)

Proyek ini menyediakan **SDK Client** di dalam folder `sdk/` agar Anda dapat langsung menggunakannya di aplikasi Monitoring Map FTTH (baik PHP maupun Node.js/JavaScript) tanpa membuat ulang kode koneksi OLT:

### A. Integrasi pada Proyek Berbasis PHP (`sdk/OltRedamanClient.php`)
```php
<?php
require_once 'C:/xampp/htdocs/olt-redaman-api/sdk/OltRedamanClient.php';

use OltRedaman\Sdk\OltRedamanClient;

// Mode Direct (memanggil langsung class internal di server yang sama — sangat cepat)
$oltClient = new OltRedamanClient();

// 1. Ambil seluruh data gabungan dari OLT HSGQ & HIOSO
$response = $oltClient->getAll();
if ($response['success']) {
    foreach ($response['data'] as $onu) {
        echo "{$onu['olt_brand']} | {$onu['onu_name']} ({$onu['sn']}) -> Rx: {$onu['rx_power_formatted']}\n";
    }
}

// 2. Cari redaman 1 ONU spesifik berdasarkan SN/MAC atau Nama Pelanggan untuk marker peta
$onuDetail = $oltClient->findOnu('00:11:41:17:D6:48');
if ($onuDetail) {
    $rxPower = $onuDetail['rx_power']; // contoh: -22.52
    $status  = $onuDetail['status'];   // 'online' atau 'offline'
}
```

### B. Integrasi pada Proyek Berbasis Node.js / Browser (`sdk/olt-redaman-client.js`)
```javascript
const OltRedamanClient = require('C:/xampp/htdocs/olt-redaman-api/sdk/olt-redaman-client.js');

const oltApi = new OltRedamanClient({
    baseUrl: 'http://localhost/olt-redaman-api/api'
});

async function updateMapOpticalPower() {
    // Ambil gabungan HSGQ + HIOSO
    const result = await oltApi.getAll();
    if (result.success) {
        console.log('Total ONU:', result.summary.total_onu);
        console.log('Data ONU:', result.data);
    }

    // Atau cari 1 ONU berdasarkan nama / SN
    const singleOnu = await oltApi.findOnu('LITA');
    console.log('Detail ONU LITA:', singleOnu);
}
```

---

## 🧪 Pengujian & Diagnosa Masalah

Untuk menguji koneksi OLT dan memvalidasi pembacaan redaman:
1. **Via Browser**: Buka `http://localhost/olt-redaman-api/` lalu klik **"Tes Koneksi HSGQ"**, **"Tes Koneksi HIOSO"**, atau **"Diagnosa Semua OLT"**.
2. **Via Shortcut Windows**: Klik dua kali **`test-connection.bat`**.
3. **Via Terminal CLI**:
   ```powershell
   C:\xampp\php\php.exe cli\test-olt.php
   ```
   Jika OLT tidak dapat diakses, sistem akan menampilkan pesan kesalahan yang jelas beserta daftar saran perbaikan (apakah kendala berada pada jaringan/VPN, port tertutup, SNMP community belum sesuai, atau username/password salah).

---

## 🔒 Catatan Keamanan Repository GitHub

- File `.env` **tidak pernah** dimasukkan ke dalam Git karena telah diblokir oleh `.gitignore`.
- Gunakan file `.env.example` sebagai acuan saat melakukan *clone* di perangkat baru.
- Untuk mengunggah pembaruan ke GitHub, cukup jalankan **`push-github.bat`** atau perintah Git standar di dalam folder `C:\xampp\htdocs\olt-redaman-api`.


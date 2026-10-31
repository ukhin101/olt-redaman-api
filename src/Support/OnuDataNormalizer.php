<?php

namespace OltRedaman\Support;

use OltRedaman\Config\EnvLoader;

/**
 * ============================================================================
 * OnuDataNormalizer - Normalisasi, Kategorisasi & Validasi Data Redaman ONU
 * ============================================================================
 * Mengubah raw data dari berbagai firmware OLT HSGQ maupun HIOSO menjadi
 * satu format JSON standar yang konsisten untuk Monitoring Map FTTH.
 */
class OnuDataNormalizer
{
    /**
     * Menormalisasi nilai daya optik (Rx Power / Tx Power) ke float (dBm) atau null.
     * Menangani format string ("-22.5181", "-22.5 dBm", "---", "N/A") maupun
     * format integer bertanda 16-bit dari firmware HSGQ/C-Data/BDCOM.
     *
     * @param mixed $rawValue Nilai mentah dari OLT
     * @param bool  $isTx     Apakah nilai ini adalah Tx Power (biasanya positif 0.5 s/d 5.0 dBm)
     * @return float|null     Nilai dalam dBm (dibulatkan 2 desimal) atau null jika tidak tersedia/LOS
     */
    public static function parseOpticalPower($rawValue, bool $isTx = false): ?float
    {
        if ($rawValue === null || $rawValue === '' || $rawValue === false) {
            return null;
        }

        if (is_string($rawValue)) {
            $cleaned = trim($rawValue);
            // Hapus prefix SNMP jika ada (STRING:, INTEGER:, Gauge32:, kutip, dBm)
            $cleaned = preg_replace('/^(STRING|INTEGER|Gauge32|Counter32|Hex-STRING)\s*:\s*/i', '', $cleaned);
            $cleaned = str_replace(['"', "'", 'dBm', 'dbm', 'mW', 'mw'], '', $cleaned);
            $cleaned = trim($cleaned);

            if (
                $cleaned === '' ||
                in_array(strtolower($cleaned), ['---', '--', '-', 'n/a', 'na', 'null', 'none', 'nil', 'offline', 'los', 'inf', '-inf'], true)
            ) {
                return null;
            }

            if (!preg_match('/[-+]?\d+(?:\.\d+)?/', $cleaned, $matches)) {
                return null;
            }
            $val = (float)$matches[0];
        } elseif (is_numeric($rawValue)) {
            $val = (float)$rawValue;
        } else {
            return null;
        }

        // Nilai sentinel kosong / tidak valid dari OLT
        if ($val == 0.0 || $val == 65535.0 || $val == -65535.0 || $val == 32767.0 || $val == -32768.0) {
            return null;
        }

        // Konversi nilai unsigned 16-bit ke signed 16-bit (umum pada firmware HSGQ/EPON)
        if ($val > 32767) {
            $val -= 65536;
        }

        // Konversi skala desimal jika dikirim dalam satuan 0.01 dBm atau 0.1 dBm
        $abs = abs($val);
        if ($abs >= 500) {
            // Contoh: -2252 -> -22.52 dBm (atau -4000 -> -40.00)
            $val /= 100.0;
        } elseif ($abs > 55) {
            // Contoh: -225 -> -22.5 dBm
            $val /= 10.0;
        }

        // Validasi rentang fisik optik FTTH
        if ($isTx) {
            // Tx Power ONU normal berada di rentang -10 dBm s/d +10 dBm (umumnya +0.5 s/d +4.5 dBm)
            if ($val <= -35.0 || $val > 15.0) {
                return null;
            }
        } else {
            // Rx Power ONU normal berada di rentang -45 dBm s/d 0 dBm (nilai <= -40 biasanya LOS)
            if ($val <= -40.0 || $val > 5.0) {
                return null;
            }
        }

        return round($val, 2);
    }

    /**
     * Menentukan kategori kualitas sinyal Rx Power berdasarkan standar FTTH.
     *
     * @param float|null $rxPower Nilai Rx Power dalam dBm
     * @param bool       $isOnline Status online ONU
     * @return array{quality: string, label: string, color: string}
     */
    public static function evaluateSignalQuality(?float $rxPower, bool $isOnline = true): array
    {
        if ($rxPower === null || !$isOnline) {
            return [
                'quality' => 'no_signal',
                'label'   => 'No Signal / Offline',
                'color'   => 'gray',
            ];
        }

        $excThreshold  = EnvLoader::getFloat('SIGNAL_EXCELLENT_THRESHOLD', -18.0);
        $goodThreshold = EnvLoader::getFloat('SIGNAL_GOOD_THRESHOLD', -23.0);
        $fairThreshold = EnvLoader::getFloat('SIGNAL_FAIR_THRESHOLD', -26.0);
        $critThreshold = EnvLoader::getFloat('SIGNAL_CRITICAL_THRESHOLD', -27.0);

        if ($rxPower > $excThreshold) {
            return [
                'quality' => 'excellent',
                'label'   => "Excellent (> {$excThreshold} dBm)",
                'color'   => 'cyan',
            ];
        }
        if ($rxPower >= $goodThreshold) {
            return [
                'quality' => 'good',
                'label'   => "Good ({$excThreshold} s/d {$goodThreshold} dBm)",
                'color'   => 'green',
            ];
        }
        if ($rxPower >= $fairThreshold) {
            return [
                'quality' => 'fair',
                'label'   => "Fair ({$goodThreshold} s/d {$fairThreshold} dBm)",
                'color'   => 'yellow',
            ];
        }
        if ($rxPower >= $critThreshold) {
            return [
                'quality' => 'poor',
                'label'   => "Poor / Lemah ({$fairThreshold} s/d {$critThreshold} dBm)",
                'color'   => 'orange',
            ];
        }

        return [
            'quality' => 'critical',
            'label'   => "Critical / Sangat Lemah (< {$critThreshold} dBm)",
            'color'   => 'red',
        ];
    }

    /**
     * Menormalisasi status ONU ke bentuk standar ('online' atau 'offline').
     */
    public static function normalizeStatus($rawStatus, ?float $rxPower = null): array
    {
        $str = strtolower(trim((string)$rawStatus));
        $onlineTokens = ['1', 'up', 'online', 'active', 'registered', 'authenticated', 'auth', 'working', 'enable', 'enabled', 'true'];

        $isOnline = in_array($str, $onlineTokens, true);

        // Jika status dari tabel utama belum jelas namun terdapat sinyal Rx Power valid (< 0 dan > -40), maka ONU aktif
        if (!$isOnline && $rxPower !== null && $rxPower < 0.0 && $rxPower > -39.5) {
            $isOnline = true;
        }

        return [
            'status'       => $isOnline ? 'online' : 'offline',
            'status_label' => $isOnline ? 'Online' : 'Offline',
            'is_online'    => $isOnline,
            'raw_status'   => (string)$rawStatus,
        ];
    }

    /**
     * Menormalisasi satu baris data ONU menjadi struktur objek standar.
     */
    public static function normalizeOnuRecord(array $input, string $brand, string $oltName, string $oltHost, string $fetchedAt): array
    {
        $rxPower = array_key_exists('rx_power_parsed', $input)
            ? $input['rx_power_parsed']
            : self::parseOpticalPower($input['rx_power'] ?? null, false);

        $txPower = array_key_exists('tx_power_parsed', $input)
            ? $input['tx_power_parsed']
            : self::parseOpticalPower($input['tx_power'] ?? null, true);

        $oltRxPower = self::parseOpticalPower($input['olt_rx_power'] ?? null, false);

        $statusInfo = self::normalizeStatus($input['status'] ?? 'offline', $rxPower);
        $signalInfo = self::evaluateSignalQuality($rxPower, $statusInfo['is_online']);

        $ponPort   = (string)($input['pon_port'] ?? '1');
        $onuNumber = isset($input['onu_number']) ? (int)$input['onu_number'] : null;
        $onuId     = (string)($input['onu_id'] ?? ($onuNumber !== null ? "{$ponPort}/{$onuNumber}" : $ponPort));
        $onuIndex  = (string)($input['onu_index'] ?? $onuId);

        $snRaw = trim((string)($input['sn'] ?? 'N/A'));
        if ($snRaw === '' || $snRaw === '-') {
            $snRaw = 'N/A';
        }

        $macAddr = null;
        if (preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $snRaw)) {
            $macAddr = strtoupper(str_replace('-', ':', $snRaw));
            $snRaw   = $macAddr;
        } elseif (!empty($input['mac_address'])) {
            $macAddr = strtoupper(trim((string)$input['mac_address']));
        }

        $downReason = trim((string)($input['last_down_reason'] ?? ''));
        if (in_array(strtolower($downReason), ['', '-', '—', 'none', 'null', 'n/a'], true)) {
            $downReason = null;
        }

        $downTime = trim((string)($input['last_down_time'] ?? ''));
        if (in_array(strtolower($downTime), ['', '-', '—', 'none', 'null', 'n/a', '0000-00-00 00:00:00'], true)) {
            $downTime = null;
        }

        return [
            'olt_brand'          => strtoupper($brand),
            'olt_name'           => $oltName,
            'olt_host'           => $oltHost,
            'onu_id'             => $onuId,
            'onu_index'          => $onuIndex,
            'pon_port'           => $ponPort,
            'pon_label'          => stripos($ponPort, 'PON') === 0 ? $ponPort : "PON {$ponPort}",
            'onu_number'         => $onuNumber,
            'onu_name'           => trim((string)($input['onu_name'] ?? "ONU-{$onuId}")),
            'sn'                 => $snRaw,
            'mac_address'        => $macAddr,
            'status'             => $statusInfo['status'],
            'status_label'       => $statusInfo['status_label'],
            'is_online'          => $statusInfo['is_online'],
            'rx_power'           => $rxPower,
            'rx_power_formatted' => $rxPower !== null ? number_format($rxPower, 2, '.', '') . ' dBm' : null,
            'tx_power'           => $txPower,
            'tx_power_formatted' => $txPower !== null ? number_format($txPower, 2, '.', '') . ' dBm' : null,
            'olt_rx_power'       => $oltRxPower,
            'signal_quality'     => $signalInfo['quality'],
            'signal_label'       => $signalInfo['label'],
            'signal_color'       => $signalInfo['color'],
            'distance_m'         => isset($input['distance_m']) && is_numeric($input['distance_m']) ? (int)$input['distance_m'] : null,
            'temperature_c'      => isset($input['temperature_c']) && is_numeric($input['temperature_c']) ? round((float)$input['temperature_c'], 1) : null,
            'model'              => !empty($input['model']) ? trim((string)$input['model']) : null,
            'vendor'             => !empty($input['vendor']) ? trim((string)$input['vendor']) : null,
            'last_down_reason'   => $downReason,
            'last_down_time'     => $downTime,
            'fetched_at'         => $fetchedAt,
        ];
    }

    /**
     * Menyaring daftar ONU berdasarkan parameter query (pon_port, status, search, sn, weak_signal).
     */
    public static function applyFilters(array $onuList, array $filters = []): array
    {
        if (empty($filters)) {
            return array_values($onuList);
        }

        $ponFilter    = isset($filters['pon_port']) && $filters['pon_port'] !== '' ? trim((string)$filters['pon_port']) : null;
        $statusFilter = isset($filters['status']) && $filters['status'] !== '' ? strtolower(trim((string)$filters['status'])) : null;
        $snFilter     = isset($filters['sn']) && $filters['sn'] !== '' ? strtolower(str_replace([':', '-'], '', trim((string)$filters['sn']))) : null;
        $searchFilter = isset($filters['search']) && $filters['search'] !== '' ? strtolower(trim((string)$filters['search'])) : null;
        $weakOnly     = !empty($filters['weak_only']) || !empty($filters['weak_signal']);
        $weakThreshold = isset($filters['weak_threshold']) && is_numeric($filters['weak_threshold'])
            ? (float)$filters['weak_threshold']
            : EnvLoader::getFloat('SIGNAL_FAIR_THRESHOLD', -26.0);

        $filtered = array_filter($onuList, function (array $onu) use ($ponFilter, $statusFilter, $snFilter, $searchFilter, $weakOnly, $weakThreshold): bool {
            if ($ponFilter !== null) {
                $cleanPonFilter = preg_replace('/^pon\s*/i', '', $ponFilter);
                $onuPon         = preg_replace('/^pon\s*/i', '', (string)$onu['pon_port']);
                if ($onuPon !== $cleanPonFilter && (string)$onu['pon_port'] !== $ponFilter) {
                    return false;
                }
            }

            if ($statusFilter !== null && $statusFilter !== 'all') {
                if (in_array($statusFilter, ['online', 'up', '1'], true) && !$onu['is_online']) {
                    return false;
                }
                if (in_array($statusFilter, ['offline', 'down', '0'], true) && $onu['is_online']) {
                    return false;
                }
            }

            if ($snFilter !== null) {
                $cleanOnuSn = strtolower(str_replace([':', '-'], '', (string)($onu['sn'] ?? '')));
                if (strpos($cleanOnuSn, $snFilter) === false) {
                    return false;
                }
            }

            if ($weakOnly) {
                if ($onu['rx_power'] === null || $onu['rx_power'] > $weakThreshold) {
                    return false;
                }
            }

            if ($searchFilter !== null) {
                $haystack = strtolower(implode(' ', [
                    $onu['onu_id'] ?? '',
                    $onu['onu_name'] ?? '',
                    $onu['sn'] ?? '',
                    $onu['pon_label'] ?? '',
                    $onu['model'] ?? '',
                    $onu['olt_name'] ?? '',
                    $onu['olt_brand'] ?? '',
                ]));
                if (strpos($haystack, $searchFilter) === false) {
                    return false;
                }
            }

            return true;
        });

        return array_values($filtered);
    }

    /**
     * Menghitung ringkasan statistik dan hasil validasi pembacaan redaman.
     */
    public static function buildSummaryAndValidation(array $onuList): array
    {
        $total   = count($onuList);
        $online  = 0;
        $offline = 0;

        $distribution = [
            'excellent' => 0,
            'good'      => 0,
            'fair'      => 0,
            'poor'      => 0,
            'critical'  => 0,
            'no_signal' => 0,
        ];

        $rxValues = [];
        $txValues = [];
        $warnings = [];

        foreach ($onuList as $onu) {
            if (!empty($onu['is_online'])) {
                $online++;
            } else {
                $offline++;
            }

            $q = $onu['signal_quality'] ?? 'no_signal';
            if (isset($distribution[$q])) {
                $distribution[$q]++;
            } else {
                $distribution['no_signal']++;
            }

            if (isset($onu['rx_power']) && $onu['rx_power'] !== null) {
                $rxValues[] = (float)$onu['rx_power'];
                if ($onu['rx_power'] < EnvLoader::getFloat('SIGNAL_CRITICAL_THRESHOLD', -27.0)) {
                    $warnings[] = sprintf(
                        "Redaman kritis pada %s (%s - %s): %.2f dBm",
                        $onu['onu_name'] ?? $onu['onu_id'],
                        $onu['onu_id'],
                        $onu['sn'] ?? 'N/A',
                        $onu['rx_power']
                    );
                }
            }

            if (isset($onu['tx_power']) && $onu['tx_power'] !== null) {
                $txValues[] = (float)$onu['tx_power'];
            }
        }

        $rxCount = count($rxValues);
        $txCount = count($txValues);

        $summary = [
            'total_onu'           => $total,
            'online_count'        => $online,
            'offline_count'       => $offline,
            'signal_distribution' => $distribution,
            'rx_power_stats'      => [
                'readable_count' => $rxCount,
                'average_dbm'    => $rxCount > 0 ? round(array_sum($rxValues) / $rxCount, 2) : null,
                'strongest_dbm'  => $rxCount > 0 ? max($rxValues) : null,
                'weakest_dbm'    => $rxCount > 0 ? min($rxValues) : null,
            ],
            'tx_power_stats'      => [
                'readable_count' => $txCount,
                'average_dbm'    => $txCount > 0 ? round(array_sum($txValues) / $txCount, 2) : null,
            ],
        ];

        $validation = [
            'is_valid'            => $total > 0 && ($rxCount > 0 || $online === 0),
            'total_records_read'  => $total,
            'valid_rx_readings'   => $rxCount,
            'valid_tx_readings'   => $txCount,
            'critical_onu_count'  => $distribution['critical'] + $distribution['poor'],
            'warnings'            => array_slice($warnings, 0, 25),
            'validation_message'  => $total === 0
                ? 'Tidak ada ONU yang terbaca dari perangkat OLT.'
                : ($rxCount > 0
                    ? "Validasi berhasil: {$rxCount} dari {$online} ONU online memiliki pembacaan Rx Power optik yang valid."
                    : 'Data daftar ONU terbaca, namun tidak ada nilai Rx Power aktif (semua ONU mungkin sedang offline).'),
        ];

        return [
            'summary'    => $summary,
            'validation' => $validation,
        ];
    }
}


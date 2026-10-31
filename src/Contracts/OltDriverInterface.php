<?php

namespace OltRedaman\Contracts;

/**
 * ============================================================================
 * OltDriverInterface - Kontrak Standar Driver OLT (HSGQ & HIOSO)
 * ============================================================================
 * Memastikan setiap modul driver merek OLT memiliki method yang konsisten
 * sehingga mudah dikembangkan, diuji, dan diintegrasikan secara terpisah.
 */
interface OltDriverInterface
{
    /**
     * Mengembalikan nama merek OLT (misal: "HSGQ" atau "HIOSO").
     */
    public function getBrand(): string;

    /**
     * Mengembalikan nama identitas perangkat OLT dari konfigurasi.
     */
    public function getName(): string;

    /**
     * Menguji koneksi jaringan, autentikasi/SNMP, dan kesiapan pembacaan data OLT.
     *
     * @return array{
     *   success: bool,
     *   brand: string,
     *   olt_name: string,
     *   host: string,
     *   method_used: string,
     *   latency_ms: float|null,
     *   message: string,
     *   diagnostics: array<string, mixed>,
     *   checked_at: string
     * }
     */
    public function testConnection(): array;

    /**
     * Mengambil seluruh data redaman optik (Rx Power, Tx Power, Status, SN, Port PON)
     * dari perangkat OLT dalam struktur yang telah dinormalisasi.
     *
     * @param array<string, mixed> $filters Filter opsional (pon_port, status, search, sn)
     * @return array{
     *   success: bool,
     *   brand: string,
     *   olt_name: string,
     *   olt_host: string,
     *   method_used: string,
     *   fetched_at: string,
     *   execution_time_ms: float,
     *   summary: array<string, mixed>,
     *   validation: array<string, mixed>,
     *   data: array<int, array<string, mixed>>,
     *   error?: string,
     *   diagnostics?: array<string, mixed>
     * }
     */
    public function fetchOpticalData(array $filters = []): array;
}

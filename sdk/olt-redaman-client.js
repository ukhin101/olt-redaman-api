/**
 * ============================================================================
 * OltRedamanClient (JavaScript / Node.js / Browser SDK)
 * ============================================================================
 * Modul client siap pakai untuk mengintegrasikan OLT Redaman API (HSGQ & HIOSO)
 * ke dalam proyek Monitoring Map FTTH (Node.js server.js maupun Browser Leaflet).
 *
 * Contoh Penggunaan di Node.js / Express (misal server.js Map Monitoring):
 * ```js
 * const OltRedamanClient = require('C:/xampp/htdocs/olt-redaman-api/sdk/olt-redaman-client.js');
 * const oltApi = new OltRedamanClient({ baseUrl: 'http://localhost/olt-redaman-api/api' });
 *
 * const combined = await oltApi.getAll();
 * const onu = await oltApi.findOnu('00:11:41:17:D6:48');
 * ```
 *
 * Contoh Penggunaan di Browser HTML:
 * ```html
 * <script src="http://localhost/olt-redaman-api/sdk/olt-redaman-client.js"></script>
 * <script>
 *   const oltApi = new OltRedamanClient({ baseUrl: 'http://localhost/olt-redaman-api/api' });
 *   oltApi.getAll().then(res => console.log(res.data));
 * </script>
 * ```
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.OltRedamanClient = factory();
    }
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    class OltRedamanClient {
        /**
         * @param {Object} options
         * @param {string} [options.baseUrl='http://localhost/olt-redaman-api/api']
         * @param {string} [options.apiKey='']
         */
        constructor(options = {}) {
            this.baseUrl = (options.baseUrl || 'http://localhost/olt-redaman-api/api').replace(/\/+$/, '');
            this.apiKey = options.apiKey || '';
        }

        async _request(endpoint, params = {}) {
            const url = new URL(this.baseUrl + '/' + endpoint.replace(/^\/+/, ''));
            Object.keys(params).forEach((key) => {
                if (params[key] !== undefined && params[key] !== null && params[key] !== '') {
                    url.searchParams.append(key, String(params[key]));
                }
            });

            const headers = { Accept: 'application/json' };
            if (this.apiKey) {
                headers['X-API-Key'] = this.apiKey;
            }

            const response = await fetch(url.toString(), {
                method: 'GET',
                headers,
            });

            return await response.json();
        }

        /**
         * Mengambil data redaman dari OLT HSGQ.
         * @param {Object} [filters] { pon_port, status, sn, search, weak_only, refresh }
         */
        async getHsgq(filters = {}) {
            return this._request('hsgq.php', filters);
        }

        /**
         * Mengambil data redaman dari OLT HIOSO.
         * @param {Object} [filters] { pon_port, status, sn, search, weak_only, refresh }
         */
        async getHioso(filters = {}) {
            return this._request('hioso.php', filters);
        }

        /**
         * Mengambil data redaman gabungan dari kedua OLT (HSGQ + HIOSO).
         * @param {Object} [filters] { pon_port, status, sn, search, weak_only, refresh }
         */
        async getAll(filters = {}) {
            return this._request('all.php', filters);
        }

        /**
         * Mencari 1 ONU berdasarkan SN, MAC Address, atau Nama Pelanggan.
         * @param {string} identifier SN / MAC / Nama ONU
         * @param {string} [brand='all'] 'all' | 'hsgq' | 'hioso'
         * @param {boolean} [refresh=false]
         */
        async findOnu(identifier, brand = 'all', refresh = false) {
            const endpointMap = {
                hsgq: 'hsgq.php',
                hioso: 'hioso.php',
                all: 'all.php',
            };
            const targetEndpoint = endpointMap[String(brand).toLowerCase()] || 'all.php';
            return this._request(targetEndpoint, {
                onu: identifier,
                refresh: refresh ? 1 : '',
            });
        }

        /**
         * Menguji koneksi ke OLT HSGQ, HIOSO, atau keduanya sekaligus memvalidasi pembacaan redaman.
         * @param {string} [brand='all'] 'all' | 'hsgq' | 'hioso'
         * @param {boolean} [validate=true]
         */
        async testConnection(brand = 'all', validate = true) {
            return this._request('test.php', {
                brand,
                validate: validate ? 1 : 0,
            });
        }
    }

    return OltRedamanClient;
});

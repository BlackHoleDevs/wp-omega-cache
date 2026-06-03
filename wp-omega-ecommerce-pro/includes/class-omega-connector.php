<?php
/**
 * OmegaDrive Connector for WordPress
 * Speaks RESP over TCP/UDS with sub-microsecond overhead.
 */

class Omega_Connector {
    private $handle;
    private $is_uds;
    public $connection_error = '';

    public function __construct($fallback_host = '172.19.0.1', $port = 6380) {
        // Try UDS first (Linux High Performance)
        if (file_exists('/tmp/airdb.sock')) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen
            $this->handle = @fsockopen("unix:///tmp/airdb.sock", -1, $errno, $errstr, 1);
            if ($this->handle) {
                $this->is_uds = true;
                return;
            }
        }
        
        // Fallback to TCP (Docker / Windows / Mac)
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen
        $this->handle = @fsockopen($fallback_host, $port, $errno, $errstr, 1);
        if ($this->handle) {
            $this->is_uds = false;
        } else {
            $this->connection_error = "TCP Connection Failed to $fallback_host:$port - ($errno) $errstr";
        }
    }

    public function set($key, $value, $ttl = 3600) {
        if (!$this->handle) return false;
        
        // Build RESP MSET or SET
        $cmd = "*3\r\n$3\r\nSET\r\n$" . strlen($key) . "\r\n$key\r\n$" . strlen($value) . "\r\n$value\r\n";
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        fwrite($this->handle, $cmd);
        fgets($this->handle); // Read OK
        return true;
    }

    public function get($key) {
        if (!$this->handle) return false;
        
        $cmd = "*2\r\n$3\r\nGET\r\n$" . strlen($key) . "\r\n$key\r\n";
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        fwrite($this->handle, $cmd);
        
        $header = fgets($this->handle);
        if ($header[0] === '$') {
            $len = (int)substr($header, 1);
            if ($len === -1) return false;
            
            $data = '';
            while (strlen($data) < $len) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
                $data .= fread($this->handle, $len - strlen($data));
            }
            fgets($this->handle); // Skip CRLF
            return $data;
        }
        return false;
    }

    public function flush() {
        if (!$this->handle) return false;
        
        $cmd = "*1\r\n$7\r\nFLUSHDB\r\n";
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        fwrite($this->handle, $cmd);
        $res = fgets($this->handle);
        return (strpos($res, 'OK') !== false || strpos($res, '+OK') !== false);
    }

    public function close() {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        if ($this->handle) fclose($this->handle);
    }
}

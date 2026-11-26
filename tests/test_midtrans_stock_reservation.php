<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/stock_reservation.php';

/**
 * Isolated Mock Database Engine for Beautyland Testing
 */
class MockDbResult
{
    private array $rows;
    private int $pointer = 0;

    public function __construct(array $rows = [])
    {
        $this->rows = array_values($rows);
        $this->pointer = 0;
    }

    public function fetch_assoc(): ?array
    {
        if ($this->pointer < count($this->rows)) {
            return $this->rows[$this->pointer++];
        }
        return null;
    }

    public function num_rows(): int
    {
        return count($this->rows);
    }
}

class MockDbStmt
{
    private MockDbConn $conn;
    private string $sql;
    private array $boundParams = [];
    public int $affected_rows = 0;
    public int $errno = 0;
    public string $error = '';
    private ?MockDbResult $lastResult = null;

    public function __construct(MockDbConn $conn, string $sql)
    {
        $this->conn = $conn;
        $this->sql = $sql;
    }

    public function bind_param(string $types, mixed &...$vars): bool
    {
        $this->boundParams = [];
        foreach ($vars as $idx => &$var) {
            $this->boundParams[$idx] = &$var;
        }
        return true;
    }

    public function execute(?array $params = null): bool
    {
        $execParams = $params ?? $this->boundParams;
        $result = $this->conn->executeMockSql($this->sql, $execParams, $this);
        if ($result === false) {
            return false;
        }
        if ($result instanceof MockDbResult) {
            $this->lastResult = $result;
        }
        return true;
    }

    public function get_result(): MockDbResult|false
    {
        return $this->lastResult ?? new MockDbResult([]);
    }

    public function close(): bool
    {
        return true;
    }
}

class MockDbConn extends mysqli
{
    public array $tables = [
        'produk' => [],
        'reservasi_stok' => [],
        'transaksi' => [],
    ];

    private ?array $snapshot = null;
    public int $connect_errno = 0;
    public int $errno = 0;
    public string $error = '';
    public int|string $insert_id = 0;
    public bool $simulateUpdateFailure = false;

    public function __construct()
    {
        // Suppress native mysqli constructor
    }

    #[\ReturnTypeWillChange]
    public function ping(): bool
    {
        return true;
    }

    #[\ReturnTypeWillChange]
    public function begin_transaction(int $flags = 0, ?string $name = null): bool
    {
        $this->snapshot = unserialize(serialize($this->tables));
        return true;
    }

    #[\ReturnTypeWillChange]
    public function commit(int $flags = 0, ?string $name = null): bool
    {
        $this->snapshot = null;
        return true;
    }

    #[\ReturnTypeWillChange]
    public function rollback(int $flags = 0, ?string $name = null): bool
    {
        if ($this->snapshot !== null) {
            $this->tables = $this->snapshot;
            $this->snapshot = null;
        }
        return true;
    }

    #[\ReturnTypeWillChange]
    public function prepare(string $query): MockDbStmt|false
    {
        return new MockDbStmt($this, $query);
    }

    public function executeMockSql(string $sql, array $params, MockDbStmt $stmt): MockDbResult|bool
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $sql));

        // 1. SELECT from reservasi_stok
        if (str_starts_with($normalized, 'SELECT id_reservasi, id_produk, jumlah, status_reservasi FROM reservasi_stok WHERE id_transaksi = ?')) {
            $id_trx = (int)$params[0];
            $rows = [];
            foreach ($this->tables['reservasi_stok'] as $row) {
                if ((int)$row['id_transaksi'] === $id_trx) {
                    $rows[] = $row;
                }
            }
            usort($rows, fn($a, $b) => ((int)$a['id_produk'] <=> (int)$b['id_produk']) ?: ((int)$a['id_reservasi'] <=> (int)$b['id_reservasi']));
            return new MockDbResult($rows);
        }

        // 2. SELECT id_produk, stok_direservasi FROM produk WHERE id_produk = ? FOR UPDATE
        if (str_starts_with($normalized, 'SELECT id_produk, stok_direservasi FROM produk WHERE id_produk = ? FOR UPDATE')) {
            $id_prod = (int)$params[0];
            $found = null;
            foreach ($this->tables['produk'] as $p) {
                if ((int)$p['id_produk'] === $id_prod) {
                    $found = [
                        'id_produk' => (int)$p['id_produk'],
                        'stok_direservasi' => (int)$p['stok_direservasi'],
                    ];
                    break;
                }
            }
            return new MockDbResult($found ? [$found] : []);
        }

        // 3. SELECT id_produk, stok, stok_direservasi FROM produk WHERE id_produk = ? FOR UPDATE
        if (str_starts_with($normalized, 'SELECT id_produk, stok, stok_direservasi FROM produk WHERE id_produk = ? FOR UPDATE')) {
            $id_prod = (int)$params[0];
            $found = null;
            foreach ($this->tables['produk'] as $p) {
                if ((int)$p['id_produk'] === $id_prod) {
                    $found = [
                        'id_produk' => (int)$p['id_produk'],
                        'stok' => (int)$p['stok'],
                        'stok_direservasi' => (int)$p['stok_direservasi'],
                    ];
                    break;
                }
            }
            return new MockDbResult($found ? [$found] : []);
        }

        // 4. SELECT id_produk, nama_produk, stok, stok_direservasi FROM produk WHERE id_produk = ? FOR UPDATE (reserve_stock)
        if (str_starts_with($normalized, 'SELECT id_produk, nama_produk, stok, stok_direservasi FROM produk WHERE id_produk = ? FOR UPDATE')) {
            $id_prod = (int)$params[0];
            $found = null;
            foreach ($this->tables['produk'] as $p) {
                if ((int)$p['id_produk'] === $id_prod) {
                    $found = [
                        'id_produk' => (int)$p['id_produk'],
                        'nama_produk' => (string)($p['nama_produk'] ?? 'Produk ' . $id_prod),
                        'stok' => (int)$p['stok'],
                        'stok_direservasi' => (int)$p['stok_direservasi'],
                    ];
                    break;
                }
            }
            return new MockDbResult($found ? [$found] : []);
        }

        // 5. UPDATE produk SET stok_direservasi = stok_direservasi - ? WHERE id_produk = ? AND stok_direservasi >= ?
        if (str_starts_with($normalized, 'UPDATE produk SET stok_direservasi = stok_direservasi - ? WHERE id_produk = ? AND stok_direservasi >= ?')) {
            if ($this->simulateUpdateFailure) {
                $stmt->affected_rows = 0;
                return false;
            }
            $qty = (int)$params[0];
            $id_prod = (int)$params[1];
            $min_reserved = (int)$params[2];

            $affected = 0;
            foreach ($this->tables['produk'] as &$p) {
                if ((int)$p['id_produk'] === $id_prod && (int)$p['stok_direservasi'] >= $min_reserved) {
                    $p['stok_direservasi'] -= $qty;
                    $affected++;
                    break;
                }
            }
            $stmt->affected_rows = $affected;
            return true;
        }

        // 6. UPDATE produk SET stok = stok - ?, stok_direservasi = stok_direservasi - ? WHERE id_produk = ? AND stok >= ? AND stok_direservasi >= ?
        if (str_starts_with($normalized, 'UPDATE produk SET stok = stok - ?, stok_direservasi = stok_direservasi - ? WHERE id_produk = ? AND stok >= ? AND stok_direservasi >= ?')) {
            if ($this->simulateUpdateFailure) {
                $stmt->affected_rows = 0;
                return false;
            }
            $qty_stok = (int)$params[0];
            $qty_res = (int)$params[1];
            $id_prod = (int)$params[2];
            $min_stok = (int)$params[3];
            $min_res = (int)$params[4];

            $affected = 0;
            foreach ($this->tables['produk'] as &$p) {
                if ((int)$p['id_produk'] === $id_prod && (int)$p['stok'] >= $min_stok && (int)$p['stok_direservasi'] >= $min_res) {
                    $p['stok'] -= $qty_stok;
                    $p['stok_direservasi'] -= $qty_res;
                    $affected++;
                    break;
                }
            }
            $stmt->affected_rows = $affected;
            return true;
        }

        // 7. UPDATE reservasi_stok SET status_reservasi = 'Dilepas' WHERE id_reservasi = ?
        if (str_starts_with($normalized, "UPDATE reservasi_stok SET status_reservasi = 'Dilepas' WHERE id_reservasi = ?")) {
            if ($this->simulateUpdateFailure) {
                $stmt->affected_rows = 0;
                return false;
            }
            $id_res = (int)$params[0];
            $affected = 0;
            foreach ($this->tables['reservasi_stok'] as &$r) {
                if ((int)$r['id_reservasi'] === $id_res && $r['status_reservasi'] === 'Aktif') {
                    $r['status_reservasi'] = 'Dilepas';
                    $affected++;
                    break;
                }
            }
            $stmt->affected_rows = $affected;
            return true;
        }

        // 8. UPDATE reservasi_stok SET status_reservasi = 'Dikonversi' WHERE id_reservasi = ?
        if (str_starts_with($normalized, "UPDATE reservasi_stok SET status_reservasi = 'Dikonversi' WHERE id_reservasi = ?")) {
            if ($this->simulateUpdateFailure) {
                $stmt->affected_rows = 0;
                return false;
            }
            $id_res = (int)$params[0];
            $affected = 0;
            foreach ($this->tables['reservasi_stok'] as &$r) {
                if ((int)$r['id_reservasi'] === $id_res && $r['status_reservasi'] === 'Aktif') {
                    $r['status_reservasi'] = 'Dikonversi';
                    $affected++;
                    break;
                }
            }
            $stmt->affected_rows = $affected;
            return true;
        }

        // 9. SELECT id_transaksi, status_pembayaran FROM transaksi WHERE id_transaksi = ? FOR UPDATE
        if (str_starts_with($normalized, 'SELECT id_transaksi, status_pembayaran FROM transaksi WHERE id_transaksi = ? FOR UPDATE')) {
            $id_trx = (int)$params[0];
            $found = null;
            foreach ($this->tables['transaksi'] as $t) {
                if ((int)$t['id_transaksi'] === $id_trx) {
                    $found = [
                        'id_transaksi' => (int)$t['id_transaksi'],
                        'status_pembayaran' => (string)$t['status_pembayaran'],
                    ];
                    break;
                }
            }
            return new MockDbResult($found ? [$found] : []);
        }

        // 10. UPDATE transaksi SET snap_status = 'Gagal' WHERE id_transaksi = ? AND status_pembayaran = 'Menunggu Pembayaran'
        if (str_starts_with($normalized, "UPDATE transaksi SET snap_status = 'Gagal' WHERE id_transaksi = ? AND status_pembayaran = 'Menunggu Pembayaran'")) {
            $id_trx = (int)$params[0];
            $affected = 0;
            foreach ($this->tables['transaksi'] as &$t) {
                if ((int)$t['id_transaksi'] === $id_trx && $t['status_pembayaran'] === 'Menunggu Pembayaran') {
                    $t['snap_status'] = 'Gagal';
                    $affected++;
                    break;
                }
            }
            $stmt->affected_rows = $affected;
            return true;
        }

        // 11. UPDATE transaksi SET snap_status = 'Perlu Rekonsiliasi' WHERE id_transaksi = ? AND status_pembayaran = 'Menunggu Pembayaran'
        if (str_starts_with($normalized, "UPDATE transaksi SET snap_status = 'Perlu Rekonsiliasi' WHERE id_transaksi = ? AND status_pembayaran = 'Menunggu Pembayaran'")) {
            $id_trx = (int)$params[0];
            $affected = 0;
            foreach ($this->tables['transaksi'] as &$t) {
                if ((int)$t['id_transaksi'] === $id_trx && $t['status_pembayaran'] === 'Menunggu Pembayaran') {
                    $t['snap_status'] = 'Perlu Rekonsiliasi';
                    $affected++;
                    break;
                }
            }
            $stmt->affected_rows = $affected;
            return true;
        }

        // Default: return empty success
        $stmt->affected_rows = 1;
        return true;
    }
}

/**
 * Runner function for Phase 3C Snap Failure Simulation
 */
function simulate_phase_3c(MockDbConn $conn, int $id_transaksi, array &$session): array
{
    $release_succeeded = false;
    $conn->begin_transaction();
    try {
        $stmt_lock_trx = $conn->prepare("
            SELECT id_transaksi, status_pembayaran 
            FROM transaksi 
            WHERE id_transaksi = ? 
            FOR UPDATE
        ");
        if (!$stmt_lock_trx) {
            throw new RuntimeException("Gagal mempersiapkan kueri lock transaksi untuk Tahap 3C.");
        }
        $stmt_lock_trx->bind_param("i", $id_transaksi);
        $stmt_lock_trx->execute();
        $locked_trx = $stmt_lock_trx->get_result()->fetch_assoc();
        $stmt_lock_trx->close();

        if (!$locked_trx) {
            throw new RuntimeException("Transaksi [{$id_transaksi}] tidak ditemukan untuk Tahap 3C.");
        }

        $locked_pay_status = (string)($locked_trx['status_pembayaran'] ?? '');
        if (in_array($locked_pay_status, ['Lunas', 'Dibatalkan', 'Refund'], true)) {
            $conn->rollback();
            return [
                'http_code' => 409,
                'release_succeeded' => false,
                'message' => 'Status pesanan telah berubah.'
            ];
        }

        $rel = release_stock_reservation($conn, $id_transaksi, true);
        if (!$rel['success']) {
            throw new RuntimeException("Release reservasi gagal: " . ($rel['message'] ?? 'Tidak diketahui'));
        }

        $stmt_fail = $conn->prepare("
            UPDATE transaksi 
            SET snap_status = 'Gagal' 
            WHERE id_transaksi = ? AND status_pembayaran = 'Menunggu Pembayaran'
        ");
        if (!$stmt_fail) {
            throw new RuntimeException("Gagal mempersiapkan kueri pembaruan snap_status.");
        }
        $stmt_fail->bind_param("i", $id_transaksi);
        $stmt_fail->execute();
        $stmt_fail->close();

        $conn->commit();
        $release_succeeded = true;
    } catch (\Throwable $rel_err) {
        $conn->rollback();

        try {
            $stmt_recon = $conn->prepare("
                UPDATE transaksi 
                SET snap_status = 'Perlu Rekonsiliasi' 
                WHERE id_transaksi = ? AND status_pembayaran = 'Menunggu Pembayaran'
            ");
            if ($stmt_recon) {
                $stmt_recon->bind_param("i", $id_transaksi);
                $stmt_recon->execute();
                $stmt_recon->close();
            }
        } catch (\Throwable $ignored) {
        }
    }

    if ($release_succeeded && isset($session['pending_order'])) {
        $session['pending_order']['checkout_idempotency_key'] = bin2hex(random_bytes(16));
    }

    if ($release_succeeded) {
        return [
            'http_code' => 500,
            'release_succeeded' => true,
            'message' => 'Gagal menginisiasi pembayaran dengan gateway Midtrans.'
        ];
    } else {
        return [
            'http_code' => 500,
            'release_succeeded' => false,
            'message' => 'Gagal menginisiasi pembayaran dan gagal melepaskan reservasi stok. Pesanan Anda memerlukan rekonsiliasi.'
        ];
    }
}

/**
 * Runner function for Phase 3B Timeout Simulation
 */
function simulate_phase_3b_timeout(MockDbConn $conn, int $id_transaksi, array &$session): array
{
    $stmt_recon = $conn->prepare("
        UPDATE transaksi 
        SET snap_status = 'Perlu Rekonsiliasi' 
        WHERE id_transaksi = ? AND status_pembayaran = 'Menunggu Pembayaran'
    ");
    if ($stmt_recon) {
        $stmt_recon->bind_param("i", $id_transaksi);
        $stmt_recon->execute();
        $stmt_recon->close();
    }

    return [
        'http_code' => 504,
        'message' => 'Gateway pembayaran mengalami kendala waktu (timeout).'
    ];
}

// =============================================================================
// TEST SUITE EXECUTION (12 Mandatory Scenarios)
// =============================================================================
$results = [];

function record_test(string $name, bool $pass, string $detail = ''): void {
    global $results;
    $status = $pass ? 'PASS' : 'FAIL';
    $results[] = [
        'scenario' => $name,
        'status' => $status,
        'detail' => $detail
    ];
    echo ($pass ? " [PASS] " : " [FAIL] ") . $name . ($detail ? " - " . $detail : "") . "\n";
}

echo "=== Menjalankan Test Suite Validasi Reservasi Stok & Midtrans P1/P2 ===\n\n";

// -----------------------------------------------------------------------------
// Skenario 1: Snap failure dan release berhasil
// Transaksi dilepas dan idempotency key baru hanya dibuat setelah commit.
// -----------------------------------------------------------------------------
$conn = new MockDbConn();
$conn->tables['produk'] = [
    ['id_produk' => 1, 'nama_produk' => 'Toner', 'stok' => 10, 'stok_direservasi' => 2]
];
$conn->tables['reservasi_stok'] = [
    ['id_reservasi' => 101, 'id_transaksi' => 1, 'id_produk' => 1, 'jumlah' => 2, 'status_reservasi' => 'Aktif']
];
$conn->tables['transaksi'] = [
    ['id_transaksi' => 1, 'status_pembayaran' => 'Menunggu Pembayaran', 'snap_status' => 'Menunggu']
];
$old_idem_key = 'idem_key_1111';
$session = [
    'pending_order' => [
        'checkout_idempotency_key' => $old_idem_key
    ]
];

$res1 = simulate_phase_3c($conn, 1, $session);
$t1 = $conn->tables['transaksi'][0];
$r1 = $conn->tables['reservasi_stok'][0];
$p1 = $conn->tables['produk'][0];
$new_idem_key = $session['pending_order']['checkout_idempotency_key'];

$pass1 = ($res1['release_succeeded'] === true)
    && ($t1['snap_status'] === 'Gagal')
    && ($r1['status_reservasi'] === 'Dilepas')
    && ($p1['stok_direservasi'] === 0)
    && ($new_idem_key !== $old_idem_key)
    && (strlen($new_idem_key) === 32);

record_test(
    "1. Snap failure dan release berhasil: transaksi dilepas dan idempotency key baru dibuat setelah commit",
    $pass1,
    "snap_status=Gagal, reservasi=Dilepas, stok_res=0, key_changed=" . ($new_idem_key !== $old_idem_key ? 'true' : 'false')
);

// -----------------------------------------------------------------------------
// Skenario 2: Snap failure dan release gagal
// Rollback, idempotency key tetap sama, reservasi tidak berubah.
// -----------------------------------------------------------------------------
$conn = new MockDbConn();
$conn->tables['produk'] = [
    ['id_produk' => 1, 'nama_produk' => 'Toner', 'stok' => 10, 'stok_direservasi' => 0] // Stok reservasi 0, tidak cukup untuk release 2
];
$conn->tables['reservasi_stok'] = [
    ['id_reservasi' => 101, 'id_transaksi' => 2, 'id_produk' => 1, 'jumlah' => 2, 'status_reservasi' => 'Aktif']
];
$conn->tables['transaksi'] = [
    ['id_transaksi' => 2, 'status_pembayaran' => 'Menunggu Pembayaran', 'snap_status' => 'Menunggu']
];
$old_idem_key2 = 'idem_key_2222';
$session2 = [
    'pending_order' => [
        'checkout_idempotency_key' => $old_idem_key2
    ]
];

$res2 = simulate_phase_3c($conn, 2, $session2);
$t2 = $conn->tables['transaksi'][0];
$r2 = $conn->tables['reservasi_stok'][0];
$p2 = $conn->tables['produk'][0];
$new_idem_key2 = $session2['pending_order']['checkout_idempotency_key'];

$pass2 = ($res2['release_succeeded'] === false)
    && ($t2['snap_status'] === 'Perlu Rekonsiliasi')
    && ($r2['status_reservasi'] === 'Aktif')
    && ($new_idem_key2 === $old_idem_key2);

record_test(
    "2. Snap failure dan release gagal: rollback, idempotency key tetap sama, reservasi tidak berubah",
    $pass2,
    "snap_status=Perlu Rekonsiliasi, reservasi=Aktif, key_unchanged=" . ($new_idem_key2 === $old_idem_key2 ? 'true' : 'false')
);

// -----------------------------------------------------------------------------
// Skenario 3: Timeout ambigu
// Idempotency key tetap sama dan reservasi tidak dilepas.
// -----------------------------------------------------------------------------
$conn = new MockDbConn();
$conn->tables['produk'] = [
    ['id_produk' => 1, 'nama_produk' => 'Toner', 'stok' => 10, 'stok_direservasi' => 2]
];
$conn->tables['reservasi_stok'] = [
    ['id_reservasi' => 101, 'id_transaksi' => 3, 'id_produk' => 1, 'jumlah' => 2, 'status_reservasi' => 'Aktif']
];
$conn->tables['transaksi'] = [
    ['id_transaksi' => 3, 'status_pembayaran' => 'Menunggu Pembayaran', 'snap_status' => 'Menunggu']
];
$old_idem_key3 = 'idem_key_3333';
$session3 = [
    'pending_order' => [
        'checkout_idempotency_key' => $old_idem_key3
    ]
];

$res3 = simulate_phase_3b_timeout($conn, 3, $session3);
$t3 = $conn->tables['transaksi'][0];
$r3 = $conn->tables['reservasi_stok'][0];
$p3 = $conn->tables['produk'][0];
$new_idem_key3 = $session3['pending_order']['checkout_idempotency_key'];

$pass3 = ($res3['http_code'] === 504)
    && ($t3['snap_status'] === 'Perlu Rekonsiliasi')
    && ($r3['status_reservasi'] === 'Aktif')
    && ($p3['stok_direservasi'] === 2)
    && ($new_idem_key3 === $old_idem_key3);

record_test(
    "3. Timeout ambigu: idempotency key tetap sama dan reservasi tidak dilepas",
    $pass3,
    "http_504=true, snap_status=Perlu Rekonsiliasi, reservasi=Aktif, key_unchanged=" . ($new_idem_key3 === $old_idem_key3 ? 'true' : 'false')
);

// -----------------------------------------------------------------------------
// Skenario 4: Semua reservasi Aktif
// Release berhasil, status reservasi menjadi Dilepas, stok reservasi berkurang.
// -----------------------------------------------------------------------------
$conn = new MockDbConn();
$conn->tables['produk'] = [
    ['id_produk' => 1, 'nama_produk' => 'Toner', 'stok' => 10, 'stok_direservasi' => 3],
    ['id_produk' => 2, 'nama_produk' => 'Serum', 'stok' => 15, 'stok_direservasi' => 2]
];
$conn->tables['reservasi_stok'] = [
    ['id_reservasi' => 201, 'id_transaksi' => 4, 'id_produk' => 1, 'jumlah' => 3, 'status_reservasi' => 'Aktif'],
    ['id_reservasi' => 202, 'id_transaksi' => 4, 'id_produk' => 2, 'jumlah' => 2, 'status_reservasi' => 'Aktif']
];

$res4 = release_stock_reservation($conn, 4, true);
$pass4 = ($res4['success'] === true)
    && ($res4['released'] === 2)
    && ($conn->tables['produk'][0]['stok_direservasi'] === 0)
    && ($conn->tables['produk'][1]['stok_direservasi'] === 0)
    && ($conn->tables['reservasi_stok'][0]['status_reservasi'] === 'Dilepas')
    && ($conn->tables['reservasi_stok'][1]['status_reservasi'] === 'Dilepas');

record_test(
    "4. Semua reservasi Aktif: release berhasil",
    $pass4,
    "released=2, status_reservasi=Dilepas, stok_res1=0, stok_res2=0"
);

// -----------------------------------------------------------------------------
// Skenario 5: Semua reservasi Dilepas
// Idempotent success, tanpa perubahan stok.
// -----------------------------------------------------------------------------
$conn = new MockDbConn();
$conn->tables['produk'] = [
    ['id_produk' => 1, 'nama_produk' => 'Toner', 'stok' => 10, 'stok_direservasi' => 5]
];
$conn->tables['reservasi_stok'] = [
    ['id_reservasi' => 301, 'id_transaksi' => 5, 'id_produk' => 1, 'jumlah' => 2, 'status_reservasi' => 'Dilepas'],
    ['id_reservasi' => 302, 'id_transaksi' => 5, 'id_produk' => 1, 'jumlah' => 1, 'status_reservasi' => 'Dilepas']
];

$res5 = release_stock_reservation($conn, 5, true);
$pass5 = ($res5['success'] === true)
    && ($res5['already_released'] === true)
    && ($res5['released'] === 0)
    && ($conn->tables['produk'][0]['stok_direservasi'] === 5); // Tidak boleh berubah

record_test(
    "5. Semua reservasi Dilepas: idempotent success",
    $pass5,
    "success=true, already_released=true, stok_res_tidak_berubah=5"
);

// -----------------------------------------------------------------------------
// Skenario 6: Semua reservasi Dikonversi
// Release ditolak.
// -----------------------------------------------------------------------------
$conn = new MockDbConn();
$conn->tables['produk'] = [
    ['id_produk' => 1, 'nama_produk' => 'Toner', 'stok' => 8, 'stok_direservasi' => 0]
];
$conn->tables['reservasi_stok'] = [
    ['id_reservasi' => 401, 'id_transaksi' => 6, 'id_produk' => 1, 'jumlah' => 2, 'status_reservasi' => 'Dikonversi']
];

$res6 = release_stock_reservation($conn, 6, true);
$pass6 = ($res6['success'] === false)
    && ($res6['already_converted'] === true)
    && ($conn->tables['reservasi_stok'][0]['status_reservasi'] === 'Dikonversi');

record_test(
    "6. Semua reservasi Dikonversi: release ditolak",
    $pass6,
    "success=false, already_converted=true"
);

// -----------------------------------------------------------------------------
// Skenario 7: Reservasi dengan status campuran
// Operasi ditolak tanpa perubahan parsial.
// -----------------------------------------------------------------------------
$conn = new MockDbConn();
$conn->tables['produk'] = [
    ['id_produk' => 1, 'nama_produk' => 'Toner', 'stok' => 10, 'stok_direservasi' => 4]
];
$conn->tables['reservasi_stok'] = [
    ['id_reservasi' => 501, 'id_transaksi' => 7, 'id_produk' => 1, 'jumlah' => 2, 'status_reservasi' => 'Aktif'],
    ['id_reservasi' => 502, 'id_transaksi' => 7, 'id_produk' => 1, 'jumlah' => 2, 'status_reservasi' => 'Dikonversi']
];

$res7 = release_stock_reservation($conn, 7, true);
$pass7 = ($res7['success'] === false)
    && ($res7['needs_reconciliation'] === true)
    && ($conn->tables['reservasi_stok'][0]['status_reservasi'] === 'Aktif')
    && ($conn->tables['reservasi_stok'][1]['status_reservasi'] === 'Dikonversi')
    && ($conn->tables['produk'][0]['stok_direservasi'] === 4); // Tidak ada perubahan parsial

record_test(
    "7. Reservasi dengan status campuran: operasi ditolak tanpa perubahan parsial",
    $pass7,
    "success=false, needs_reconciliation=true, row_status_tetap, stok_res_tetap=4"
);

// -----------------------------------------------------------------------------
// Skenario 8: Reservasi tidak ditemukan pada transaksi manual
// Kompatibilitas tetap terjaga (sukses no-op).
// -----------------------------------------------------------------------------
$conn = new MockDbConn();
$conn->tables['reservasi_stok'] = [];

$res8 = release_stock_reservation($conn, 8, false); // is_midtrans = false
$pass8 = ($res8['success'] === true)
    && ($res8['released'] === 0)
    && ($res8['already_released'] === false);

record_test(
    "8. Reservasi tidak ditemukan pada transaksi manual: kompatibilitas tetap terjaga",
    $pass8,
    "is_midtrans=false, success=true, released=0"
);

// -----------------------------------------------------------------------------
// Skenario 9: Reservasi tidak ditemukan pada transaksi Midtrans
// Tidak dianggap sebagai release berhasil (success = false, needs_reconciliation).
// -----------------------------------------------------------------------------
$conn = new MockDbConn();
$conn->tables['reservasi_stok'] = [];

$res9 = release_stock_reservation($conn, 9, true); // is_midtrans = true
$pass9 = ($res9['success'] === false)
    && ($res9['needs_reconciliation'] === true);

record_test(
    "9. Reservasi tidak ditemukan pada transaksi Midtrans: tidak dianggap sebagai release berhasil",
    $pass9,
    "is_midtrans=true, success=false, needs_reconciliation=true"
);

// -----------------------------------------------------------------------------
// Skenario 10: Stok fisik tidak mencukupi saat konversi
// Operasi gagal tanpa mengubah data (tanpa silent GREATEST clamping).
// -----------------------------------------------------------------------------
$conn = new MockDbConn();
$conn->tables['produk'] = [
    ['id_produk' => 1, 'nama_produk' => 'Toner', 'stok' => 1, 'stok_direservasi' => 2] // Stok fisik 1 < jumlah 2
];
$conn->tables['reservasi_stok'] = [
    ['id_reservasi' => 601, 'id_transaksi' => 10, 'id_produk' => 1, 'jumlah' => 2, 'status_reservasi' => 'Aktif']
];

$conn->begin_transaction();
$res10 = convert_stock_reservation($conn, 10);
if (!$res10['success']) {
    $conn->rollback();
} else {
    $conn->commit();
}

$pass10 = ($res10['success'] === false)
    && ($res10['needs_reconciliation'] === true)
    && ($conn->tables['produk'][0]['stok'] === 1) // Tidak berubah dan tidak menjadi 0 diam-diam
    && ($conn->tables['produk'][0]['stok_direservasi'] === 2)
    && ($conn->tables['reservasi_stok'][0]['status_reservasi'] === 'Aktif');

record_test(
    "10. Stok fisik tidak mencukupi: operasi gagal tanpa mengubah data",
    $pass10,
    "success=false, stok=1 (tidak diubah nol diam-diam), status=Aktif"
);

// -----------------------------------------------------------------------------
// Skenario 11: Stok reservasi tidak mencukupi saat konversi/release
// Operasi gagal tanpa mengubah data.
// -----------------------------------------------------------------------------
$conn = new MockDbConn();
$conn->tables['produk'] = [
    ['id_produk' => 1, 'nama_produk' => 'Toner', 'stok' => 10, 'stok_direservasi' => 1] // Stok reservasi 1 < jumlah 2
];
$conn->tables['reservasi_stok'] = [
    ['id_reservasi' => 701, 'id_transaksi' => 11, 'id_produk' => 1, 'jumlah' => 2, 'status_reservasi' => 'Aktif']
];

$conn->begin_transaction();
$res11 = release_stock_reservation($conn, 11, true);
if (!$res11['success']) {
    $conn->rollback();
} else {
    $conn->commit();
}

$pass11 = ($res11['success'] === false)
    && ($res11['needs_reconciliation'] === true)
    && ($conn->tables['produk'][0]['stok_direservasi'] === 1)
    && ($conn->tables['reservasi_stok'][0]['status_reservasi'] === 'Aktif');

record_test(
    "11. Stok reservasi tidak mencukupi: operasi gagal tanpa mengubah data",
    $pass11,
    "success=false, stok_direservasi=1 (tidak negatif / 0 diam-diam), status=Aktif"
);

// -----------------------------------------------------------------------------
// Skenario 12: Kegagalan UPDATE: seluruh transaksi rollback
// -----------------------------------------------------------------------------
$conn = new MockDbConn();
$conn->tables['produk'] = [
    ['id_produk' => 1, 'nama_produk' => 'Toner', 'stok' => 10, 'stok_direservasi' => 2],
    ['id_produk' => 2, 'nama_produk' => 'Serum', 'stok' => 10, 'stok_direservasi' => 2]
];
$conn->tables['reservasi_stok'] = [
    ['id_reservasi' => 801, 'id_transaksi' => 12, 'id_produk' => 1, 'jumlah' => 2, 'status_reservasi' => 'Aktif'],
    ['id_reservasi' => 802, 'id_transaksi' => 12, 'id_produk' => 2, 'jumlah' => 2, 'status_reservasi' => 'Aktif']
];

$conn->begin_transaction();
// Simulasikan kegagalan update pada database
$conn->simulateUpdateFailure = true;
$res12 = release_stock_reservation($conn, 12, true);
if (!$res12['success']) {
    $conn->rollback();
} else {
    $conn->commit();
}

$pass12 = ($res12['success'] === false)
    && ($conn->tables['produk'][0]['stok_direservasi'] === 2)
    && ($conn->tables['produk'][1]['stok_direservasi'] === 2)
    && ($conn->tables['reservasi_stok'][0]['status_reservasi'] === 'Aktif')
    && ($conn->tables['reservasi_stok'][1]['status_reservasi'] === 'Aktif');

record_test(
    "12. Kegagalan UPDATE: seluruh transaksi rollback",
    $pass12,
    "success=false, rollback_berhasil, seluruh baris kembali ke status awal"
);

echo "\n=== Rangkuman Hasil Pengujian ===\n";
$total = count($results);
$passed = count(array_filter($results, fn($r) => $r['status'] === 'PASS'));
$failed = $total - $passed;
echo "Total: {$total}, PASS: {$passed}, FAIL: {$failed}\n";

if ($failed > 0) {
    exit(1);
}
exit(0);

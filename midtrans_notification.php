<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

$raw_input = file_get_contents('php://input');
if ($raw_input === false || trim($raw_input) === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Empty request body.']);
    exit;
}

$payload = json_decode($raw_input, true);
if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload.']);
    exit;
}

$order_id           = $payload['order_id'] ?? null;
$status_code        = $payload['status_code'] ?? null;
$gross_amount       = $payload['gross_amount'] ?? null;
$signature_key      = $payload['signature_key'] ?? null;
$transaction_status = $payload['transaction_status'] ?? null;

if (
    !is_string($order_id) || trim($order_id) === '' ||
    !is_string($status_code) || trim($status_code) === '' ||
    (!is_string($gross_amount) && !is_numeric($gross_amount)) ||
    !is_string($signature_key) || trim($signature_key) === '' ||
    !is_string($transaction_status) || trim($transaction_status) === ''
) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing or invalid required notification fields.']);
    exit;
}

require_once __DIR__ . '/includes/conn.php';
require_once __DIR__ . '/includes/stock_reservation.php';
require_once __DIR__ . '/includes/midtrans.php';

$server_key = \Midtrans\Config::$serverKey ?? ($_ENV['MIDTRANS_SERVER_KEY'] ?? '');
if (empty($server_key)) {
    error_log("Midtrans server key is not configured.");
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Gateway configuration error.']);
    exit;
}

$raw_amount_str = trim((string)$gross_amount);
$expected_signature = hash('sha512', (string)$order_id . (string)$status_code . $raw_amount_str . $server_key);

if (!hash_equals($expected_signature, (string)$signature_key)) {
    error_log("CRITICAL: Invalid Midtrans webhook signature for order [{$order_id}]");
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid signature key.']);
    exit;
}

if (!preg_match('/^[1-9][0-9]{0,11}(\.00)?$/', $raw_amount_str)) {
    error_log("CRITICAL: Invalid gross_amount format for order [{$order_id}]: [{$raw_amount_str}]");
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid gross amount format.']);
    exit;
}

$normalized_amount_str = preg_replace('/\.00$/', '', $raw_amount_str);

$conn->begin_transaction();

try {
    $stmt_find = $conn->prepare("
        SELECT id_transaksi, total_harga, status_pembayaran, midtrans_status, snap_status, 
               stok_dikurangi, midtrans_payment_type, midtrans_transaction_id 
        FROM transaksi 
        WHERE midtrans_order_id = ? 
        FOR UPDATE
    ");
    if (!$stmt_find) {
        throw new RuntimeException("Gagal menyiapkan kueri pencarian transaksi.");
    }

    $stmt_find->bind_param("s", $order_id);
    $stmt_find->execute();
    $trx = $stmt_find->get_result()->fetch_assoc();
    $stmt_find->close();

    if (!$trx) {
        $conn->rollback();
        error_log("Midtrans webhook: Order ID not found [{$order_id}]");
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Transaction not found.']);
        exit;
    }

    $db_total = (int)$trx['total_harga'];
    if ((string)$db_total !== $normalized_amount_str || (int)$normalized_amount_str !== $db_total) {
        error_log("CRITICAL: Gross amount mismatch for order [{$order_id}]. Midtrans: {$normalized_amount_str}, DB: {$db_total}");
        $conn->rollback();
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Gross amount mismatch.']);
        exit;
    }

    $trx_id             = (int)$trx['id_transaksi'];
    $current_pay_status = (string)($trx['status_pembayaran'] ?? '');
    $stok_dikurangi     = (int)($trx['stok_dikurangi'] ?? 0);
    $fraud_status       = isset($payload['fraud_status']) ? trim((string)$payload['fraud_status']) : '';
    $payment_type       = isset($payload['payment_type']) ? (string)$payload['payment_type'] : ($trx['midtrans_payment_type'] ?? null);
    $midtrans_trx_id    = isset($payload['transaction_id']) ? (string)$payload['transaction_id'] : ($trx['midtrans_transaction_id'] ?? null);
    $raw_payload_json   = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($current_pay_status === 'Menunggu Pembayaran') {
        if ($transaction_status === 'settlement') {
            if ($stok_dikurangi === 0) {
                $conv = convert_stock_reservation($conn, $trx_id);
                $is_valid_conversion = ($conv['success'] === true && ($conv['converted'] > 0 || !empty($conv['already_converted'])));
                if (!$is_valid_conversion) {
                    error_log("CRITICAL [STOCK_CONVERSION_FAILED]: Gagal konversi reservasi stok untuk order [{$order_id}], trx [{$trx_id}]: " . ($conv['message'] ?? 'Konversi tidak valid'));
                    throw new RuntimeException("Gagal mengonversi reservasi stok: " . ($conv['message'] ?? 'Konversi tidak valid'));
                }
            }
            $stmt_up = $conn->prepare("
                UPDATE transaksi 
                SET status_pembayaran = 'Lunas', 
                    midtrans_status = 'settlement', 
                    midtrans_transaction_id = ?, 
                    midtrans_payment_type = ?, 
                    notification_payload = ?, 
                    stok_dikurangi = 1 
                WHERE id_transaksi = ?
            ");
            $stmt_up->bind_param("sssi", $midtrans_trx_id, $payment_type, $raw_payload_json, $trx_id);
            $stmt_up->execute();
            $stmt_up->close();
        } elseif ($transaction_status === 'capture') {
            if ($fraud_status === 'accept') {
                if ($stok_dikurangi === 0) {
                    $conv = convert_stock_reservation($conn, $trx_id);
                    $is_valid_conversion = ($conv['success'] === true && ($conv['converted'] > 0 || !empty($conv['already_converted'])));
                    if (!$is_valid_conversion) {
                        error_log("CRITICAL [STOCK_CONVERSION_FAILED]: Gagal konversi reservasi stok untuk order [{$order_id}], trx [{$trx_id}]: " . ($conv['message'] ?? 'Konversi tidak valid'));
                        throw new RuntimeException("Gagal mengonversi reservasi stok: " . ($conv['message'] ?? 'Konversi tidak valid'));
                    }
                }
                $stmt_up = $conn->prepare("
                    UPDATE transaksi 
                    SET status_pembayaran = 'Lunas', 
                        midtrans_status = 'capture', 
                        midtrans_transaction_id = ?, 
                        midtrans_payment_type = ?, 
                        notification_payload = ?, 
                        stok_dikurangi = 1 
                    WHERE id_transaksi = ?
                ");
                $stmt_up->bind_param("sssi", $midtrans_trx_id, $payment_type, $raw_payload_json, $trx_id);
                $stmt_up->execute();
                $stmt_up->close();
            } elseif ($fraud_status === 'challenge') {
                $stmt_up = $conn->prepare("
                    UPDATE transaksi 
                    SET midtrans_status = 'capture', 
                        midtrans_transaction_id = ?, 
                        midtrans_payment_type = ?, 
                        notification_payload = ? 
                    WHERE id_transaksi = ?
                ");
                $stmt_up->bind_param("sssi", $midtrans_trx_id, $payment_type, $raw_payload_json, $trx_id);
                $stmt_up->execute();
                $stmt_up->close();
            } elseif ($fraud_status === 'deny') {
                $rel = release_stock_reservation($conn, $trx_id);
                if (!$rel['success']) {
                    error_log("CRITICAL [STOCK_RELEASE_FAILED]: Gagal melepas reservasi stok untuk order [{$order_id}], trx [{$trx_id}]: " . ($rel['message'] ?? ''));
                    throw new RuntimeException("Gagal melepas reservasi stok: " . ($rel['message'] ?? ''));
                }
                $stmt_up = $conn->prepare("
                    UPDATE transaksi 
                    SET status_pembayaran = 'Dibatalkan', 
                        midtrans_status = 'deny', 
                        midtrans_transaction_id = ?, 
                        midtrans_payment_type = ?, 
                        notification_payload = ? 
                    WHERE id_transaksi = ?
                ");
                $stmt_up->bind_param("sssi", $midtrans_trx_id, $payment_type, $raw_payload_json, $trx_id);
                $stmt_up->execute();
                $stmt_up->close();
            } else {
                error_log("CRITICAL: Unknown or empty fraud_status [{$fraud_status}] on capture for order [{$order_id}]");
                $stmt_up = $conn->prepare("
                    UPDATE transaksi 
                    SET midtrans_status = 'capture', 
                        midtrans_transaction_id = ?, 
                        midtrans_payment_type = ?, 
                        notification_payload = ? 
                    WHERE id_transaksi = ?
                ");
                $stmt_up->bind_param("sssi", $midtrans_trx_id, $payment_type, $raw_payload_json, $trx_id);
                $stmt_up->execute();
                $stmt_up->close();
            }
        } elseif (in_array($transaction_status, ['expire', 'cancel', 'deny'], true)) {
            $rel = release_stock_reservation($conn, $trx_id);
            if (!$rel['success']) {
                error_log("CRITICAL [STOCK_RELEASE_FAILED]: Gagal melepas reservasi stok untuk order [{$order_id}], trx [{$trx_id}]: " . ($rel['message'] ?? ''));
                throw new RuntimeException("Gagal melepas reservasi stok: " . ($rel['message'] ?? ''));
            }
            $stmt_up = $conn->prepare("
                UPDATE transaksi 
                SET status_pembayaran = 'Dibatalkan', 
                    midtrans_status = ?, 
                    midtrans_transaction_id = ?, 
                    midtrans_payment_type = ?, 
                    notification_payload = ? 
                WHERE id_transaksi = ?
            ");
            $stmt_up->bind_param("ssssi", $transaction_status, $midtrans_trx_id, $payment_type, $raw_payload_json, $trx_id);
            $stmt_up->execute();
            $stmt_up->close();
        } elseif ($transaction_status === 'pending') {
            $stmt_up = $conn->prepare("
                UPDATE transaksi 
                SET midtrans_status = 'pending', 
                    midtrans_transaction_id = ?, 
                    midtrans_payment_type = ?, 
                    notification_payload = ? 
                WHERE id_transaksi = ?
            ");
            $stmt_up->bind_param("sssi", $midtrans_trx_id, $payment_type, $raw_payload_json, $trx_id);
            $stmt_up->execute();
            $stmt_up->close();
        } elseif (in_array($transaction_status, ['refund', 'partial_refund'], true)) {
            error_log("WARNING: Refund notification received for unpaid order [{$order_id}]");
            $rel = release_stock_reservation($conn, $trx_id);
            if (!$rel['success']) {
                error_log("CRITICAL [STOCK_RELEASE_FAILED]: Gagal melepas reservasi stok untuk order [{$order_id}], trx [{$trx_id}]: " . ($rel['message'] ?? ''));
                throw new RuntimeException("Gagal melepas reservasi stok: " . ($rel['message'] ?? ''));
            }
            $stmt_up = $conn->prepare("
                UPDATE transaksi 
                SET status_pembayaran = 'Dibatalkan', 
                    midtrans_status = ?, 
                    midtrans_transaction_id = ?, 
                    midtrans_payment_type = ?, 
                    notification_payload = ? 
                WHERE id_transaksi = ?
            ");
            $stmt_up->bind_param("ssssi", $transaction_status, $midtrans_trx_id, $payment_type, $raw_payload_json, $trx_id);
            $stmt_up->execute();
            $stmt_up->close();
        } else {
            $stmt_up = $conn->prepare("
                UPDATE transaksi 
                SET midtrans_status = ?, 
                    midtrans_transaction_id = ?, 
                    midtrans_payment_type = ?, 
                    notification_payload = ? 
                WHERE id_transaksi = ?
            ");
            $stmt_up->bind_param("ssssi", $transaction_status, $midtrans_trx_id, $payment_type, $raw_payload_json, $trx_id);
            $stmt_up->execute();
            $stmt_up->close();
        }
    } elseif ($current_pay_status === 'Lunas') {
        if ($transaction_status === 'settlement' || ($transaction_status === 'capture' && $fraud_status === 'accept')) {
            $stmt_up = $conn->prepare("
                UPDATE transaksi 
                SET notification_payload = ?, 
                    midtrans_transaction_id = ? 
                WHERE id_transaksi = ?
            ");
            $stmt_up->bind_param("ssi", $raw_payload_json, $midtrans_trx_id, $trx_id);
            $stmt_up->execute();
            $stmt_up->close();
        } elseif (in_array($transaction_status, ['pending', 'expire', 'cancel', 'deny'], true)) {
            $stmt_up = $conn->prepare("
                UPDATE transaksi 
                SET notification_payload = ? 
                WHERE id_transaksi = ?
            ");
            $stmt_up->bind_param("si", $raw_payload_json, $trx_id);
            $stmt_up->execute();
            $stmt_up->close();
        } elseif ($transaction_status === 'refund') {
            $stmt_up = $conn->prepare("
                UPDATE transaksi 
                SET status_pembayaran = 'Refund', 
                    midtrans_status = 'refund', 
                    notification_payload = ? 
                WHERE id_transaksi = ?
            ");
            $stmt_up->bind_param("si", $raw_payload_json, $trx_id);
            $stmt_up->execute();
            $stmt_up->close();
        } elseif ($transaction_status === 'partial_refund') {
            $stmt_up = $conn->prepare("
                UPDATE transaksi 
                SET midtrans_status = 'partial_refund', 
                    notification_payload = ? 
                WHERE id_transaksi = ?
            ");
            $stmt_up->bind_param("si", $raw_payload_json, $trx_id);
            $stmt_up->execute();
            $stmt_up->close();
        } else {
            $stmt_up = $conn->prepare("
                UPDATE transaksi 
                SET notification_payload = ? 
                WHERE id_transaksi = ?
            ");
            $stmt_up->bind_param("si", $raw_payload_json, $trx_id);
            $stmt_up->execute();
            $stmt_up->close();
        }
    } elseif ($current_pay_status === 'Dibatalkan') {
        if (in_array($transaction_status, ['expire', 'cancel', 'deny', 'pending'], true)) {
            $stmt_up = $conn->prepare("
                UPDATE transaksi 
                SET notification_payload = ? 
                WHERE id_transaksi = ?
            ");
            $stmt_up->bind_param("si", $raw_payload_json, $trx_id);
            $stmt_up->execute();
            $stmt_up->close();
        } elseif ($transaction_status === 'settlement' || ($transaction_status === 'capture' && $fraud_status === 'accept')) {
            error_log("CRITICAL [LATE_SETTLEMENT]: Order [{$order_id}] received settlement after cancellation. Funds received in Midtrans, reservation already released. Action required: Refund or contact customer.");
            $stmt_up = $conn->prepare("
                UPDATE transaksi 
                SET midtrans_status = ?, 
                    midtrans_transaction_id = ?, 
                    midtrans_payment_type = ?, 
                    notification_payload = ? 
                WHERE id_transaksi = ?
            ");
            $stmt_up->bind_param("ssssi", $transaction_status, $midtrans_trx_id, $payment_type, $raw_payload_json, $trx_id);
            $stmt_up->execute();
            $stmt_up->close();
        } elseif ($transaction_status === 'refund') {
            $stmt_up = $conn->prepare("
                UPDATE transaksi 
                SET midtrans_status = 'refund', 
                    notification_payload = ? 
                WHERE id_transaksi = ?
            ");
            $stmt_up->bind_param("si", $raw_payload_json, $trx_id);
            $stmt_up->execute();
            $stmt_up->close();
        } else {
            $stmt_up = $conn->prepare("
                UPDATE transaksi 
                SET notification_payload = ? 
                WHERE id_transaksi = ?
            ");
            $stmt_up->bind_param("si", $raw_payload_json, $trx_id);
            $stmt_up->execute();
            $stmt_up->close();
        }
    } elseif ($current_pay_status === 'Refund') {
        $stmt_up = $conn->prepare("
            UPDATE transaksi 
            SET notification_payload = ? 
            WHERE id_transaksi = ?
        ");
        $stmt_up->bind_param("si", $raw_payload_json, $trx_id);
        $stmt_up->execute();
        $stmt_up->close();
    } else {
        $stmt_up = $conn->prepare("
            UPDATE transaksi 
            SET midtrans_status = ?, 
                notification_payload = ? 
            WHERE id_transaksi = ?
        ");
        $stmt_up->bind_param("ssi", $transaction_status, $raw_payload_json, $trx_id);
        $stmt_up->execute();
        $stmt_up->close();
    }

    $conn->commit();

    http_response_code(200);
    echo json_encode(['status' => 'success', 'message' => 'Notification handled successfully.']);
    exit;

} catch (\Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli && $conn->connect_errno === 0) {
        $conn->rollback();
    }
    error_log("Database error in midtrans_notification.php for order [{$order_id}]: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database transaction failed.']);
    exit;
}

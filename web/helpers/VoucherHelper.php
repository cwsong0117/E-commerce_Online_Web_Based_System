<?php
// Small voucher helpers shared by the cart, checkout and order-cancel code.

/**
 * Get a voucher only if this user is allowed to use it right now:
 * it must be assigned to the user, active, within its dates and not used by them yet.
 * Returns the voucher row, or null when it can't be used.
 */
function findUsableVoucher(PDO $conn, int $voucherId, int $userId)
{
    $stmt = $conn->prepare("
        SELECT v.* FROM voucher v
        WHERE v.voucher_id = :voucher_id
          AND v.status = 'active'
          AND v.start_date <= CURDATE()
          AND v.end_date >= CURDATE()
          AND EXISTS (
              SELECT 1 FROM voucher_assignment va
              WHERE va.voucher_id = v.voucher_id AND va.user_id = :assigned_user_id
          )
          AND NOT EXISTS (
              SELECT 1 FROM voucher_usage vu
              WHERE vu.voucher_id = v.voucher_id AND vu.user_id = :used_user_id
          )
    ");
    $stmt->execute([
        ':voucher_id' => $voucherId,
        ':assigned_user_id' => $userId,
        ':used_user_id' => $userId
    ]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Give the voucher back to the customer when their paid order is cancelled or refunded.
 * Only call this for orders that were paid (a pending order never used up the voucher).
 */
function releaseVoucherForOrder(PDO $conn, int $orderId): void
{
    $stmt = $conn->prepare("
        DELETE vu FROM voucher_usage vu
        JOIN orders o ON o.voucher_id = vu.voucher_id AND o.user_id = vu.user_id
        WHERE o.order_id = :order_id
    ");
    $stmt->execute([':order_id' => $orderId]);
}

<?php

if (!function_exists('log_stock_movement')) {
    function log_stock_movement(
        $conn,
        int $tenant_id,
        int $product_id,
        ?int $batch_id,
        string $movement_type,
        int $qty_change,
        ?string $ref_type = null,
        ?int $ref_id = null,
        ?int $user_id = null,
        ?string $note = null
    ): bool {
        if ($qty_change === 0) {
            return true; // nothing to record
        }
        $stmt = @$conn->prepare(
            "INSERT INTO stock_movements
                (tenant_id, product_id, batch_id, movement_type, qty_change, reference_type, reference_id, user_id, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            // Table likely missing until stock_movements.sql is run — don't break business flow
            error_log('stock_movements not logged (prepare failed): ' . $conn->error);
            return false;
        }
        $ok = $stmt->bind_param(
            'iiisisiis',
            $tenant_id,
            $product_id,
            $batch_id,
            $movement_type,
            $qty_change,
            $ref_type,
            $ref_id,
            $user_id,
            $note
        ) && $stmt->execute();
        if (!$ok) {
            error_log('stock_movements insert failed: ' . $stmt->error);
        }
        $stmt->close();
        return $ok;
    }
}

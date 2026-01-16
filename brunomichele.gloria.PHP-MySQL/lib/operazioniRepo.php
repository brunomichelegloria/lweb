<?php

final class OperationRepo {
    public function __construct(private PDO $pdo) {}

    public function netPositionsForBucket(int $bucketId): array {
        $sql = "SELECT
                    ISIN,
                    SUM(CASE WHEN Tipo='BUY' THEN Quantita ELSE -Quantita END) AS QuantitaNetta
                FROM Operazione
                WHERE ID_Bucket = ?
                GROUP BY ISIN";
        $st = $this->pdo->prepare($sql);
        $st->execute([$bucketId]);

        $out = [];
        while ($row = $st->fetch()) {
            $out[$row['ISIN']] = (float)$row['QuantitaNetta'];
        }
        return $out;
    }

    public function listOperationsForContent(int $bucketId, string $isin): array {
        $sql = "SELECT DataOra, Tipo, Quantita, PrezzoEseguito
                FROM Operazione
                WHERE ID_Bucket = ? AND ISIN = ?
                ORDER BY DataOra";
        $st = $this->pdo->prepare($sql);
        $st->execute([$bucketId, $isin]);
        return $st->fetchAll();
    }
}
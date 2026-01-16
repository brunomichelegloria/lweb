<?php

final class BucketRepo {
    public function __construct(private PDO $pdo) {}

    public function listChildren(int $parentId): array {
        $sql = "SELECT ID_Bucket AS idBucket, Nome AS nome, TargetPctSuPadre AS targetPctSuPadre
                FROM Bucket
                WHERE ID_Padre = ?
                ORDER BY Nome";
        $st = $this->pdo->prepare($sql);
        $st->execute([$parentId]);
        return $st->fetchAll();
    }

    public function listContents(int $bucketId): array {
        $sql = "SELECT
                    ca.ISIN AS isin,
                    ca.TargetPctNelBucket AS targetPct,
                    a.Nome AS nome,
                    a.Tipo AS tipo,
                    a.Valuta AS valuta
                FROM ContenutoAsset ca
                JOIN Asset a ON a.ISIN = ca.ISIN
                WHERE ca.ID_Bucket = ?
                ORDER BY a.Nome";
        $st = $this->pdo->prepare($sql);
        $st->execute([$bucketId]);
        return $st->fetchAll();
    }
}


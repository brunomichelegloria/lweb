<?php

final class PortfolioCalc {
    public function __construct(
        private BucketRepo $bucketRepo,
        private OperationRepo $operationRepo
    ) {}

    public function calcBucketValue(int $bucketId, array $prices): float {
        $positions = $this->operationRepo->netPositionsForBucket($bucketId);

        $valueAssets = 0.0;
        foreach ($positions as $isin => $qty) {
            if ($qty == 0.0) continue;
            $price = $prices[$isin] ?? null;
            if ($price === null) {
                continue;
            }
            $valueAssets += $qty * $price;
        }

        $children = $this->bucketRepo->listChildren($bucketId);
        $valueChildren = 0.0;
        foreach ($children as $child) {
            $valueChildren += $this->calcBucketValue((int)$child['id_bucket'], $prices);
        }

        return $valueAssets + $valueChildren;
    }
}

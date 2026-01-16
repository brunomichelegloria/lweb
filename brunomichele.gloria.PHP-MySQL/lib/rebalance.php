<?php
require_once __DIR__ . '/misc.php';

interface RebalanceNode {
    public function calculateValue(array $priceCache): void;
}

abstract class Node implements RebalanceNode {
    public ?Node $parent = null;
    public string $id;
    public string $type = 'bucket'; // 'asset', 'bucket', 'liquidita'
    public ?string $subtype = null; // 'azione', 'etf', 'obbligazione' per asset
    public float $targetPct; // % relativa al parent (già normalizzata 0-100)
    public float $targetAbs = 0.0;
    public float $valueNow = 0.0; // Valore attuale calcolato (dalla fotografia)
    public float $bandHi = 0.0;
    public float $driftAbs = 0.0;
    public array $notes = []; // Note varie sul nodo
}

class Asset extends Node {
    public string $ticker = '';
    public float $qty = 0.0; // Quantità attuale posseduta
    public float $price = 0.0; // Prezzo di mercato corrente (iniettato da priceCache)
    public float $wac = 0.0; // Costo Medio Ponderato (calcolato da getWAC)
    public float $taxRate = 0.26;
    public int $tradeStep = 1;
    public float $feeFixed = 0.0;
    public float $feeRate = 0.0;
    public bool $isBond = false;
    public bool $isLiquidity = false;
    public bool $frozenForTrading = false;
    
    // Implementazione del valore
    public function calculateValue(array $priceCache): void {
        $this->notes = [];
        $havePrice = array_key_exists($this->ticker, $priceCache);
        $p = $havePrice ? (float)$priceCache[$this->ticker] : 0.0;
        $this->price = $p;
        $priceUnit = $this->isBond ? $p / 100.0 : $p;
        if (!$havePrice) {
            // Solo per fotografia pesi: usa WAC come stima, ma blocca il trade
            $w = $this->isBond ? $this->wac / 100.0 : $this->wac;
            if ($w > 0) {
                $priceUnit = $w;
                $this->notes[] = 'used_wac_for_weight_only';
            } else {
                $this->notes[] = 'missing_market_price';
            }
            $this->frozenForTrading = true;
        }
        $this->valueNow  = $this->qty * $priceUnit;
    }
}

class Liquidita extends Node {
    public float $qty = 0.0;
    public bool $isLiquidity = true;
    public int $tradeStep = 1;

    public function calculateValue(array $priceCache): void {
        // La liquidità è il suo stesso valore
        $this->valueNow = $this->qty;
        $this->notes[] = 'liquidity_value';
    }
}

class Bucket extends Node {
    /** @var Node[] */
    public array $children = [];
    public string $name = '';

    public function calculateValue(array $priceCache): void {
        $this->valueNow = 0.0;
        foreach ($this->children as $child) {
            $child->calculateValue($priceCache);
            $this->valueNow += $child->valueNow;
        }
    }
}

function getFloatAttribute(DOMElement $element, string $name, float $default = 0.0): float {
    return (float)($element->hasAttribute($name)? $element->getAttribute($name) : $default);
}

function allocateByTarget(array $children): array {
    $sumT=0; foreach($children as $c){ $sumT+=max(0.0,$c->targetPct/100.0); }
    if ($sumT<=0) return [];
    $out=[]; foreach($children as $c){ $out[$c->id]=($c->targetPct/100.0)/$sumT; }
    return $out; // restituisce solo pesi; nel 3B moltiplichi per $deltaFromParent
}

/**
 * Funzione ricorsiva che analizza il DOM e crea l'albero di oggetti.
 * @param DOMElement $xmlElement L'elemento XML corrente.
 * @param array $globalInfo Le informazioni di configurazione globale.
 * getWAC La funzione esterna per calcolare il WAC.
 * @param DOMXPath $xp L'oggetto DOMXPath, necessario per getWAC.
 * @param string $pathPrefix Il path ID del nodo genitore (es. "/tech-giants").
 * @return Node|Asset|Bucket|Liquidita
 */
function parseXmlElementToNode(DOMElement $xmlElement, array $globalInfo, DOMXPath $xp, string $pathPrefix = ''): Node {
    $tagName = $xmlElement->tagName;
    $currentIdPart = '';
    $node = null;

    // Estrae la percentuale target (se non esiste, è 0)
    $targetElement = $xp->query('./target', $xmlElement)?->item(0);
    $targetPct = $targetElement ? (float)$targetElement->textContent : 0.0;
    
    // --- 1. Determinazione del Tipo di Nodo e Calcolo WAC ---
    
    if ($tagName === 'liquidita') {
        $node = new Liquidita();
        $node->type = 'liquidita';
        $totEl = $xp->query('./totale', $xmlElement)->item(0);
        $node->qty = $totEl ? (float)$totEl->textContent : 0.0;
        $currentIdPart = 'LIQUIDITA'; // Liquidità è al top-level
        
    } elseif (in_array($tagName, ['azione','etf','obbligazione'])) {
        $node = new Asset();
        $node->type = 'asset';
        $node->subtype = $tagName;
        $node->ticker = $xmlElement->getAttribute('ticker');
        $node->taxRate = $xmlElement->hasAttribute('taxRate') ? (float)($xmlElement->getAttribute('taxRate')/100) : $globalInfo['defaultTaxRate'];
        $node->tradeStep = (int)getFloatAttribute($xmlElement, 'tradeStep', $globalInfo['defaultTradeStep']);
        $node->isBond = ($tagName === 'obbligazione');
        
        $currentIdPart = $node->ticker; // Ticker per gli asset

        // CALCOLO WAC & QUANTITÀ INIZIALE
        // Passiamo l'elemento DOM e il DOMXPath alla funzione esterna getWAC
        list($node->wac, $node->qty) = getWAC($xmlElement, $xp); 
        
    } elseif ($tagName === 'bucket') {
        $node = new Bucket();
        $node->type = 'bucket';
        $nameEl = $xp->query('./nome', $xmlElement)->item(0);
        $node->name = $nameEl ? trim($nameEl->textContent) : '';
        $currentIdPart = $node->name ? sanitize_id($node->name) : '';

        // Gestione di ID mancanti per Bucket
        if (!$currentIdPart) {
            $node->notes[] = 'missing_name_id';
        }

    } else {
        throw new \Exception("Tipo di nodo XML non supportato: $tagName");
    }

    // --- 2. Costruzione del Path ID ---
    // Esempio: /tech-giants/AAPL
    $node->id = $pathPrefix . '/' . $currentIdPart;
    $node->targetPct = $targetPct;

    // --- 3. Ricorsione (solo per Bucket) ---
    if ($node instanceof Bucket) {
        // Il path prefisso del figlio è l'ID completo del nodo corrente
        $newPathPrefix = $node->id; 
        
        foreach ($xmlElement->childNodes as $childElement) {
            if ($childElement instanceof DOMElement) {
                if (in_array($childElement->tagName, ['azione', 'etf', 'obbligazione', 'bucket'])) {
                    $node->children[] = parseXmlElementToNode($childElement, $globalInfo, $xp, $newPathPrefix);
                }
            }
        }
    }
    
    return $node;
}


/**
 * Funzione principale per preparare l'albero del portafoglio.
 * @param string $xmlFilePath Percorso del file XML.
 * getWAC La funzione WAC fornita dall'utente.
 * @return array ['root' => Bucket, 'globalInfo' => array]
 */
function preparePortfolioTree(string $xmlFilePath): array {
    $dom = new DOMDocument();
    $dom->load($xmlFilePath);
    $xp = new DOMXPath($dom);
    $rootXml = $dom->getElementsByTagName('portafoglio')->item(0);
    $liquiditaXml = $xp->query('//liquidita')->item(0);
    
    if (!$rootXml) {
        throw new \Exception("Elemento 'portafoglio' non trovato.");
    }

    // --- 1. Estrazione Informazioni Globali ---
    $infoXml = $xp->query('//informazioni')->item(0);
    $globalInfo = [
        'commissioneFissa' => getFloatAttribute($infoXml, 'commissione', 0.0),
        'commissionePerc' => 0.0,
        'tolleranza' => getFloatAttribute($infoXml, 'tolleranza', 0.0),
        'minTrade' => getFloatAttribute($infoXml, 'commissione', 0.0) * 5,
        'accPct' => getFloatAttribute($infoXml, 'tolleranza', 0.0) / 5,
        'commissionTotal' => 0.0,
        'taxTotal' => 0.0,
        'residualCash' => 0.0,
        'cashNet' => 0.0,
        'defaultTaxRate' => 0.26, 
        'defaultTradeStep' => 1, 
        'wf_recompute_phase3' => false,
    ];

    // --- 2. Creazione Nodo ROOT ---
    $root = new Bucket();
    $root->id = '';
    $root->name = 'ROOT';
    $root->type = 'bucket';
    $root->targetPct = 100.0;

    // --- 3. Parsing Ricorsivo (assets e liquidita) ---
    $assetsXml = $xp->query('//assets')->item(0);
    
    // Parsing assets e bucket top-level
    if ($assetsXml) {
        foreach ($assetsXml->childNodes as $child) {
            if ($child instanceof DOMElement && in_array($child->tagName, ['obbligazione', 'azione', 'etf', 'bucket'])) {
                // Passiamo l'ID della root come prefisso iniziale
                $root->children[] = parseXmlElementToNode($child, $globalInfo, $xp, $root->id);
            }
        }
    }

    if ($liquiditaXml) {
        // Liquidità è un caso speciale: l'ID sarà /LIQUIDITA
        $root->children[] = parseXmlElementToNode($liquiditaXml, $globalInfo, $xp, $root->id);
    }
    
    // Restituiamo sia l'albero che le info globali
    return ['root' => $root, 'globalInfo' => $globalInfo];
}

// --- PICCOLI FIX SU UTILITY ESISTENTI ---------------------------------------

function allocateByCurrent(array $children): array {
    $sumV = 0.0; foreach ($children as $c) { $sumV += $c->valueNow; }
    if ($sumV <= 0) return [];
    $out = [];
    foreach ($children as $c) { $out[$c->id] = $c->valueNow / $sumV; }
    return $out;
}

// --- TRAVERSAL GENERICI ------------------------------------------------------

/** Applica $fn a ogni nodo dell'albero (pre-ordine). */
function walkTree(Node $node, callable $fn): void {
    $fn($node);
    if ($node instanceof Bucket) {
        foreach ($node->children as $child) {
            walkTree($child, $fn);
        }
    }
}

/** Raccoglie tutti i nodi che soddisfano $predicato (ritorna array di Node). */
function collectNodes(Node $node, callable $predicato): array {
    $out = [];
    walkTree($node, function(Node $n) use (&$out, $predicato) {
        if ($predicato($n)) $out[] = $n;
    });
    return $out;
}

/** Raccoglie tutte le foglie Asset negoziabili (o tutte le foglie se $onlyTradeable=false). */
function collectAssets(Node $root, bool $onlyTradeable = false): array {
    return collectNodes($root, function(Node $n) use ($onlyTradeable) {
        if (!($n instanceof Asset)) return false;
        if (!$onlyTradeable) return true;
        // tradeable: non frozen, ha prezzo valido (o WAC usato solo per pesi ma non per trading), non è liquidità
        if ($n->frozenForTrading) return false;
        if ($n->isLiquidity) return false;
        // prezzo valido per il trading: $n->price > 0 (il WAC viene usato solo per fotografia pesi)
        return $n->price > 0.0;
    });
}

/** Raccoglie tutti i Bucket (incluso root). */
function collectBuckets(Node $root): array {
    return collectNodes($root, fn(Node $n) => $n instanceof Bucket);
}

/** Trova il nodo liquidità (assumiamo un solo nodo di tipo Liquidita). */
function findLiquidityNode(Node $root): ?Liquidita {
    $liqs = collectNodes($root, fn(Node $n) => $n instanceof Liquidita);
    return $liqs ? $liqs[0] : null;
}

// --- FOTOGRAFIA VALORI + RICALCOLO TOTALE -----------------------------------

/**
 * Ricalcola valueNow su tutto l'albero usando $priceCache.
 * Ritorna il valore totale del portafoglio (root->valueNow).
 */
function recomputeValues(Bucket $root, array $priceCache): float {
    $root->calculateValue($priceCache);
    return (float)$root->valueNow;
}

// --- PROPAGAZIONE TARGET ASSOLUTI E BANDE ------------------------------------

/**
 * Propaga targetAbs e bande (bandLo/bandHi) dall'alto verso il basso.
 * $tolPct è in percento (es. 5 = 5%).
 * Root deve avere targetPct = 100 e riceve $V_tot come targetAbs.
 */
function propagateTargetsAndBands(Bucket $root, float $V_tot, float $tolPct): void {
    // 1) set root
    $root->targetAbs = $V_tot;

    // 2) ricorsione sui figli con accumulo di targetAbs
    $tol = max(0.0, $tolPct) / 100.0;

    $assign = function(Node $node) use (&$assign, $tol) {
        // bande e drift per il nodo corrente
        $bandLo = $node->targetAbs * (1.0 - $tol);
        $node->bandHi = $node->targetAbs * (1.0 + $tol); // già nel modello
        // NB: manteniamo solo bandHi su Node come da tua struttura; ma ci serve bandLo temporaneo:
        // lo calcoliamo "on the fly" quando serve (vedi helper qui sotto).
        $node->driftAbs = $node->valueNow - $node->targetAbs;

        if ($node instanceof Bucket) {
            // figli: targetAbs = targetPct/100 * targetAbs del padre
            foreach ($node->children as $child) {
                $child->parent = $node;
                $child->targetAbs = $node->targetAbs * max(0.0, $child->targetPct) / 100.0;
                $assign($child);
            }
        }
    };

    $assign($root);
}

/** Helper: calcola la banda bassa al volo per un nodo (coerente con bandHi già salvata). */
function bandLoOf(Node $n, float $tolPct): float {
    $tol = max(0.0, $tolPct) / 100.0;
    return $n->targetAbs * (1.0 - $tol);
}

/** Distanza relativa dal target, usata per l'ordinamento (valori grandi prima). */
function relativeTargetGap(Node $n): float {
    if ($n->targetAbs <= 0.0) {
        // se target è zero, usiamo una misura che dia precedenza allo smaltimento
        return $n->valueNow > 0 ? INF : 0.0;
    }
    return abs($n->valueNow - $n->targetAbs) / $n->targetAbs;
}

// --- COSTI, TASSE E FILTRI ECONOMICI ----------------------------------------

/** Commissione totale dato un importo lordo (valore € scambiato) e fee glob. */
function computeFee(float $grossValue, float $feeFixed, float $feeRate): float {
    return max(0.0, $feeFixed + $feeRate * $grossValue);
}

/** Imposta su vendita: opzione B (solo se price > wac). */
function computeSellTax(Asset $a, int $qty): float {
    if ($qty <= 0) return 0.0;
    $priceUnit = $a->isBond ? $a->price / 100.0 : $a->price;
    $wacUnit   = $a->isBond ? $a->wac   / 100.0 : $a->wac;
    $gainU     = max(0.0, $priceUnit - $wacUnit);
    return $gainU * $qty * max(0.0, $a->taxRate);
}

/**
 * Controllo "dimensionale" e costo/beneficio: ritorna true se il trade è sensato.
 * $benefitEuro ≈ riduzione drift in euro (≈ grossValue).
 * k è il moltiplicatore (>=1).
 */
function passesEconomicFilters(float $grossValue, float $fee, float $tax, float $minTrade, float $k): bool {
    if ($grossValue <= 0.0) return false;
    // soglia dimensionale minima: net/gross sopra minTrade (o direttamente gross ≥ minTrade, a tua scelta)
    if ($grossValue < $minTrade) return false;
    // beneficio vs costo
    $benefit = $grossValue; // semplificazione coerente con l'analisi
    $costs   = $fee + $tax;
    return $benefit >= $k * $costs;
}

// --- Helper: prezzo unitario coerente (bond = prezzo/100) -------------------
function unitPrice(Asset $a): float {
    return $a->isBond ? ($a->price / 100.0) : $a->price;
}
function unitWac(Asset $a): float {
    return $a->isBond ? ($a->wac / 100.0) : $a->wac;
}

// --- Helper: arrotonda quantità a step interi verso il basso ----------------
function floorToTradeStep(int $qty, int $step): int {
    if ($step <= 1) return max(0, $qty);
    $blocks = intdiv(max(0, $qty), $step);
    return $blocks * $step;
}

// --- Helper: applica una vendita e aggiorna cassa/ops/contatori -------------
function applySellTrade(
    Asset $a,
    int $sellQty,
    Liquidita $liq,
    array &$globalInfo,
    array &$ops,
    string $noteBase
): void {
    if ($sellQty <= 0) return;

    $pU   = unitPrice($a);
    $gross= $pU * $sellQty;

    // Fee globali (fisse + %)
    $feeFixed = $globalInfo['feeFixed'] ?? ($globalInfo['commissioneFissa'] ?? 0.0);
    $feeRate  = $globalInfo['feeRate']  ?? ($globalInfo['commissionePerc'] ?? 0.0);
    $fee      = computeFee($gross, $feeFixed, $feeRate);

    // Tasse opzione B (solo se price > wac)
    $tax = 0.0;
    $wU  = unitWac($a);
    if ($pU > 0.0 && $wU > 0.0 && $pU > $wU) {
        $tax = ($pU - $wU) * $sellQty * max(0.0, $a->taxRate);
    }

    $net = $gross - $fee - $tax;

    // Aggiorna quantità asset e liquidità
    $a->qty = max(0, $a->qty - $sellQty);
    $liq->qty += $net;

    // Totali
    $globalInfo['commissionTotal'] = ($globalInfo['commissionTotal'] ?? 0.0) + $fee;
    $globalInfo['taxTotal']        = ($globalInfo['taxTotal'] ?? 0.0) + $tax;

    // Ops (aggrego se già esiste)
    if (!isset($ops[$a->id])) {
        $ops[$a->id] = [0, ''];
    }
    $ops[$a->id][0] += -$sellQty;
    $ops[$a->id][1] = trim($ops[$a->id][1] . ' ' . $noteBase . sprintf('(gross=%.2f fee=%.2f tax=%.2f net=%.2f)', $gross, $fee, $tax, $net));
}

// --- Calcola la max qty vendibile che PASSA i filtri economici --------------
function findMaxSellQtyPassingFilters(
    Asset $a,
    float $minTrade,
    float $k,
    float $feeFixed,
    float $feeRate
): int {
    $step = max(1, (int)$a->tradeStep);
    $pU   = unitPrice($a);
    if ($pU <= 0.0) return 0;

    // Quantità massima vendibile rispettando lo step
    $maxQty = floorToTradeStep((int)$a->qty, $step);
    if ($maxQty <= 0) return 0;

    // Prova dal massimo verso il basso, a step
    for ($q = $maxQty; $q > 0; $q -= $step) {
        $gross = $pU * $q;
        $fee   = computeFee($gross, $feeFixed, $feeRate);

        // Tasse opzione B (solo se price > wac)
        $wU  = unitWac($a);
        $tax = 0.0;
        if ($pU > $wU && $wU > 0.0) {
            $tax = ($pU - $wU) * $q * max(0.0, $a->taxRate);
        }

        if (passesEconomicFilters($gross, $fee, $tax, $minTrade, $k)) {
            return $q;
        }
    }
    return 0;
}

// --- Step B0: smaltimento di tutti gli asset con target ~ 0 -----------------
/**
 * Vende (nei limiti economici) tutte le foglie con targetPct == 0 (o targetAbs == 0)
 * e qty > 0, generando cassa. Non forza micro-trade non economici.
 * Dopo l’esecuzione, conviene rifare: recomputeValues() + propagateTargetsAndBands().
 */
function phaseB0_sellToZeroTargets(
    Bucket $root,
    Liquidita $liq,
    array &$globalInfo,
    array &$ops
): void {
    // Default parametri economici
    $globalInfo['k']        = $globalInfo['k']        ?? 1.0;      // beneficio >= k * costi
    $globalInfo['minTrade'] = $globalInfo['minTrade'] ?? 20.0;     // soglia dimensionale default
    $feeFixed = $globalInfo['feeFixed'] ?? ($globalInfo['commissioneFissa'] ?? 0.0);
    $feeRate  = $globalInfo['feeRate']  ?? ($globalInfo['commissionePerc'] ?? 0.0);

    // Individua foglie con target zero e quantità > 0
    $candidates = collectNodes($root, function(Node $n) {
        if (!($n instanceof Asset)) return false;
        if ($n->isLiquidity) return false;
        // target zero in senso pratico: targetPct == 0 oppure targetAbs molto piccolo
        if ($n->targetPct > 0.0) return false;
        if ($n->qty <= 0) return false;
        // Serve prezzo di mercato per tradare
        if ($n->price <= 0.0) return false;
        // Non consideriamo frozen
        if ($n->frozenForTrading) return false;
        return true;
    });

    // Ordina i candidati in modo semplice: valore decrescente (prima i grandi)
    usort($candidates, function(Asset $a, Asset $b) {
        return ($b->valueNow <=> $a->valueNow);
    });

    foreach ($candidates as $asset) {
        $pU = unitPrice($asset);
        if ($pU <= 0.0) {
            // Non abbiamo prezzo di mercato: niente trade (già filtrato sopra, ma per sicurezza)
            if (!isset($ops[$asset->id])) { $ops[$asset->id] = [0, '']; }
            $ops[$asset->id][1] = trim($ops[$asset->id][1] . ' missing_market_price');
            continue;
        }

        // Trova la quantità massima che ha senso vendere in un unico ordine
        $qty = findMaxSellQtyPassingFilters(
            $asset,
            (float)$globalInfo['minTrade'],
            (float)$globalInfo['k'],
            (float)$feeFixed,
            (float)$feeRate
        );

        if ($qty <= 0) {
            // Bloccato da tradeStep o costi/benefici insufficienti
            if (!isset($ops[$asset->id])) { $ops[$asset->id] = [0, '']; }
            // se non è divisibile per lo step: trade_step_blocked, altrimenti costo/beneficio
            $stepFit = floorToTradeStep((int)$asset->qty, max(1, (int)$asset->tradeStep));
            $ops[$asset->id][1] = trim($ops[$asset->id][1] . ' ' . ($stepFit <= 0 ? 'trade_step_blocked' : 'skipped_cost_gt_benefit'));
            continue;
        }

        // Esegui la vendita in un colpo unico (niente spezzatini)
        applySellTrade($asset, $qty, $liq, $globalInfo, $ops, 'sell_to_zero ');
    }
}

function applyBuyTrade(
    Asset $a,
    int $buyQty,
    Liquidita $liq,
    array &$globalInfo,
    array &$ops,
    string $noteBase
): void {
    if ($buyQty <= 0) return;

    $pU    = unitPrice($a);
    $gross = $pU * $buyQty;

    // Fee globali (fisse + %)
    $feeFixed = $globalInfo['feeFixed'] ?? ($globalInfo['commissioneFissa'] ?? 0.0);
    $feeRate  = $globalInfo['feeRate']  ?? ($globalInfo['commissionePerc'] ?? 0.0);
    $fee      = computeFee($gross, $feeFixed, $feeRate);

    $netCost  = $gross + $fee;

    // Affordability
    if ($liq->qty < $netCost) return;

    // Aggiorna quantità asset e liquidità
    $a->qty += $buyQty;
    $liq->qty -= $netCost;

    // Totali
    $globalInfo['commissionTotal'] = ($globalInfo['commissionTotal'] ?? 0.0) + $fee;

    // Ops (aggrego)
    if (!isset($ops[$a->id])) $ops[$a->id] = [0, ''];
    $ops[$a->id][0] += $buyQty;
    $ops[$a->id][1] = trim($ops[$a->id][1] . ' ' . $noteBase . sprintf('(gross=%.2f fee=%.2f cost=%.2f)', $gross, $fee, $netCost));
}

function findMaxBuyQtyPassingFilters(
    Asset $a,
    Liquidita $liq,
    float $capEuro,
    float $minTrade,
    float $k,
    float $feeFixed,
    float $feeRate
): int {
    $step = max(1, (int)$a->tradeStep);
    $pU   = unitPrice($a);
    if ($pU <= 0.0) return 0;

    // Limite per cap target/accettanza
    $capQty = (int)floor($capEuro / $pU);
    $capQty = floorToTradeStep($capQty, $step);
    if ($capQty <= 0) return 0;

    // Limite di affordability (considera fee fissa: iteriamo a scendere)
    for ($q = $capQty; $q > 0; $q -= $step) {
        $gross = $pU * $q;
        $fee   = computeFee($gross, $feeFixed, $feeRate);
        $net   = $gross + $fee;
        if ($net > $liq->qty) continue;

        // filtri economici (no tasse in buy)
        if (!passesEconomicFilters($gross, $fee, 0.0, $minTrade, $k)) continue;

        return $q;
    }
    return 0;
}

// ------- Stime costo unitario per ordinamento candidati ---------------------

/** Costo per euro venduto (approssimazione: fee per € + tax per €). */
function unitSellCostPerEuro(Asset $a, float $feeFixed, float $feeRate): float {
    $pU = unitPrice($a);
    if ($pU <= 0.0) return INF;

    // usa uno step minimo come riferimento
    $qRef = max($a->tradeStep, 1);
    $gross = $pU * $qRef;
    $fee   = computeFee($gross, $feeFixed, $feeRate);

    $wU = unitWac($a);
    $tax = 0.0;
    if ($pU > $wU && $wU > 0.0) {
        $tax = ($pU - $wU) * $qRef * max(0.0, $a->taxRate);
    }
    $cost = $fee + $tax;
    return $gross > 0.0 ? $cost / $gross : INF;
}

/** Costo per euro comprato (approssimazione: fee per €). */
function unitBuyCostPerEuro(Asset $a, float $feeFixed, float $feeRate): float {
    $pU = unitPrice($a);
    if ($pU <= 0.0) return INF;

    $qRef = max($a->tradeStep, 1);
    $gross = $pU * $qRef;
    $fee   = computeFee($gross, $feeFixed, $feeRate);
    return $gross > 0.0 ? $fee / $gross : INF;
}

// ------- Costruzione liste globali e cap verso target+acc -------------------

/** Ritorna coppia [sellList, buyList] con asset tradabili out-of-band. */
function buildCandidateLists(Bucket $root, float $tolPct): array {
    $sell = [];
    $buy  = [];

    $assets = collectAssets($root, true);
    foreach ($assets as $a) {
        // nodi tradabili: prezzo>0 e non frozen (già filtrati)
        $bandLo = bandLoOf($a, $tolPct);
        if ($a->valueNow > $a->bandHi) {
            $sell[] = $a;
        } elseif ($a->valueNow < $bandLo) {
            $buy[] = $a;
        }
    }
    return [$sell, $buy];
}

/** Calcola il cap in € per vendere verso target + accettanza senza scendere sotto bandLo. */
function sellCapEuro(Asset $a, float $tolPct, float $accPct): float {
    $bandLo = bandLoOf($a, $tolPct);
    // targetAbs può essere 0 (ma in B0 li abbiamo già trattati)
    $base = max(0.0, ($a->valueNow - $a->targetAbs)); // quanto sopra target
    $acc  = max(0.0, $accPct/100.0 * $a->targetAbs);
    $capToTargetPlusAcc = $base + $acc;

    // non andare sotto bandLo
    $maxWithoutUndershoot = max(0.0, $a->valueNow - $bandLo);
    return min($capToTargetPlusAcc, $maxWithoutUndershoot);
}

/** Calcola il cap in € per comprare verso target + accettanza senza superare bandHi. */
function buyCapEuro(Asset $a, float $tolPct, float $accPct): float {
    $bandLo = bandLoOf($a, $tolPct);
    $def    = max(0.0, ($a->targetAbs - $a->valueNow)); // quanto sotto target
    $acc    = max(0.0, $accPct/100.0 * $a->targetAbs);
    $capToTargetPlusAcc = $def + $acc;

    // non oltrepassare bandHi
    $maxWithoutOvershoot = max(0.0, $a->bandHi - $a->valueNow);
    return min($capToTargetPlusAcc, $maxWithoutOvershoot);
}

// ------- Utility: deficit totale per under-band e cash disponibile ----------

function totalUnderbandDeficitEuro(Bucket $root, float $tolPct): float {
    $sum = 0.0;
    $assets = collectAssets($root, true);
    foreach ($assets as $a) {
        $bandLo = bandLoOf($a, $tolPct);
        if ($a->valueNow < $bandLo) {
            $sum += ($bandLo - $a->valueNow);
        }
    }
    return $sum;
}

function anyNodeOutOfBand(Bucket $root, float $tolPct): bool {
    $assets = collectAssets($root, true);
    foreach ($assets as $a) {
        $bandLo = bandLoOf($a, $tolPct);
        if ($a->valueNow > $a->bandHi || $a->valueNow < $bandLo) {
            return true;
        }
    }
    return false;
}

/**
 * Completa l'array $ops includendo TUTTE le foglie asset con delta=0 e una nota di stato,
 * così il report esprime chiaramente che sono stati considerati e qual è la loro situazione.
 * - Non sovrascrive gli asset che hanno già un delta (trade eseguito).
 * - Se un asset ha già note (es. insufficient_*), aggiunge lo status in coda.
 */
function finalizeOpsWithStatuses(Bucket $root, float $tolPct, array &$ops): void {
    $assets = collectAssets($root, false); // includi anche non-tradabili per status chiaro
    foreach ($assets as $a) {
        if (!($a instanceof Asset)) continue;

        $id = $a->id;
        $hasTrade = isset($ops[$id]) && (($ops[$id][0] ?? 0) !== 0);

        // Costruisci lo status
        $tags = [];

        // Prezzo mancante / frozen
        if ($a->price <= 0.0) {
            $tags[] = 'missing_market_price';
        }
        if ($a->frozenForTrading) {
            $tags[] = 'frozen_for_trading';
        }

        // Situazione rispetto a banda/target (solo se abbiamo un valore sensato)
        $bandLo = bandLoOf($a, $tolPct);
        if ($a->valueNow > 0.0 || $a->targetAbs > 0.0) {
            if ($a->valueNow > $a->bandHi) {
                $tags[] = 'over_band_residual';
            } elseif ($a->valueNow < $bandLo) {
                $tags[] = 'under_band_residual';
            } else {
                // Dentro banda: opzionalmente specifica sopra/sotto target
                if ($a->targetAbs > 0.0) {
                    if ($a->valueNow > $a->targetAbs) {
                        $tags[] = 'in_band_above_target';
                    } elseif ($a->valueNow < $a->targetAbs) {
                        $tags[] = 'in_band_below_target';
                    } else {
                        $tags[] = 'at_target';
                    }
                } else {
                    // target 0 (es. asset smaltito o da smaltire)
                    if ($a->qty > 0) {
                        $tags[] = 'target_zero_pending';
                    } else {
                        $tags[] = 'target_zero_ok';
                    }
                }
            }
        }

        // Se esiste già una riga ops senza trade (delta = 0), append status; altrimenti crea voce nuova.
        if (isset($ops[$id])) {
            // già presente: se delta=0 appendi lo status, se delta!=0 lascia il trade e appendi status comunque
            $existingNote = trim($ops[$id][1] ?? '');
            $statusNote = implode(' ', array_unique($tags));
            $ops[$id][1] = trim($existingNote . (strlen($existingNote) ? ' ' : '') . $statusNote);
        } else {
            // crea voce neutra (nessun trade)
            $ops[$id] = [0, implode(' ', array_unique($tags)) ?: 'in_band'];
        }
    }
}

// ------- Integrazione nel REBALANCE: loop completo --------------------------

function rebalance(string $xmlFilePath, array $priceCache, bool $useWACForSell = true): array {

    // --- PREPARAZIONE ---
    try {
        $data = preparePortfolioTree($xmlFilePath);
        /** @var Bucket $root */
        $root = $data['root'];
        $globalInfo = $data['globalInfo'];
        $globalInfo['useWACForSell'] = $useWACForSell;
    } catch (\Exception $e) {
        return ['ops' => [], 'summary' => ['error' => $e->getMessage()]];
    }

    // Defaults policy/economics
    $globalInfo['feeFixed']   = $globalInfo['feeFixed']   ?? ($globalInfo['commissioneFissa'] ?? 0.0);
    $globalInfo['feeRate']    = $globalInfo['feeRate']    ?? ($globalInfo['commissionePerc'] ?? 0.0);
    $globalInfo['k']          = $globalInfo['k']          ?? 1.0;
    $globalInfo['minTrade']   = $globalInfo['minTrade']   ?? 20.0;
    $globalInfo['accPct']     = $globalInfo['accPct']     ?? max(0.0, ($globalInfo['tolleranza'] ?? 0.0) / 5.0);
    $tolPct                   = $globalInfo['tolleranza'] ?? 0.0;

    // --- FOTOGRAFIA INIZIALE ---
    $V_tot_init = recomputeValues($root, $priceCache);
    $V_tot = $V_tot_init;
    propagateTargetsAndBands($root, $V_tot, $tolPct);
    $liq = findLiquidityNode($root);
    if (!$liq) $liq = new Liquidita(); // fallback, non dovrebbe capitare

    $cash_before = $liq->qty ?? 0.0;
    $fees_before = $globalInfo['commissionTotal'] ?? 0.0;
    $tax_before  = $globalInfo['taxTotal'] ?? 0.0;

    $ops = [];

    // --- B0: SELL-TO-ZERO (prima cosa) ---
    phaseB0_sellToZeroTargets($root, $liq, $globalInfo, $ops);
    // Ricalcola dopo B0
    $V_tot = recomputeValues($root, $priceCache);
    propagateTargetsAndBands($root, $V_tot, $tolPct);

    // Flag: in partenza c'erano nodi fuori banda?
    $hadOutOfBandInitially = anyNodeOutOfBand($root, $tolPct);

    // --- LOOP SELL → BUY (multi-pass) ---
    $maxPasses = 5;
    for ($pass = 1; $pass <= $maxPasses; $pass++) {

        // COSTRUISCI LISTE OUT-OF-BAND
        [$sellList, $buyList] = buildCandidateLists($root, $tolPct);

        // se nulla fuori banda → uscita (salvo cash injection case gestito sotto)
        if (empty($sellList) && empty($buyList)) {
            break;
        }

        // Calcoli economici globali
        $feeFixed = (float)$globalInfo['feeFixed'];
        $feeRate  = (float)$globalInfo['feeRate'];
        $k        = (float)$globalInfo['k'];
        $minTrade = (float)$globalInfo['minTrade'];

        // ------ SELL: se serve generare cassa per coprire under-band ---------
        $needBuyEuro  = totalUnderbandDeficitEuro($root, $tolPct);
        $cashAvail    = $liq->qty ?? 0.0;
        $didAnyTrade  = false;

        if ($cashAvail < $needBuyEuro) {
            // aggiungi asset "in banda ma sopra target"
            $extra = array_filter(collectAssets($root, true), function(Asset $a) use ($tolPct) {
                if ($a->isLiquidity) return false;
                $bandLo = bandLoOf($a, $tolPct);
                $inBand = ($a->valueNow >= $bandLo && $a->valueNow <= $a->bandHi);
                return $inBand && ($a->valueNow > $a->targetAbs) && ($a->targetAbs > 0.0);
            });
            // merge evitando duplicati
            $byId = [];
            foreach (array_merge($sellList, $extra) as $x) { $byId[$x->id] = $x; }
            $sellList = array_values($byId);
        }

        if ($cashAvail < $needBuyEuro && !empty($sellList)) {
            // Ordina SELL per distanza relativa dal target (desc), poi costo unitario più basso
            usort($sellList, function(Asset $a, Asset $b) use ($feeFixed, $feeRate) {
                $ra = relativeTargetGap($a);
                $rb = relativeTargetGap($b);
                if ($ra === $rb) {
                    $ca = unitSellCostPerEuro($a, $feeFixed, $feeRate);
                    $cb = unitSellCostPerEuro($b, $feeFixed, $feeRate);
                    return $ca <=> $cb; // meno costo prima
                }
                return $rb <=> $ra; // più lontano prima
            });

            foreach ($sellList as $a) {
                // cap verso target + accettanza senza scendere sotto bandLo
                $capEuro = sellCapEuro($a, $tolPct, (float)$globalInfo['accPct']);
                if ($capEuro <= 0.0) continue;

                // qty che passa filtri
                $qty = findMaxSellQtyPassingFilters($a, $minTrade, $k, $feeFixed, $feeRate);
                if ($qty <= 0) {
                    if (!isset($ops[$a->id])) $ops[$a->id] = [0, ''];
                    $stepFit = floorToTradeStep((int)$a->qty, max(1, (int)$a->tradeStep));
                    $ops[$a->id][1] = trim($ops[$a->id][1] . ' ' . ($stepFit <= 0 ? 'trade_step_blocked' : 'skipped_cost_gt_benefit'));
                    continue;
                }

                // non superare il cap
                $pU = unitPrice($a);
                $qtyCap = (int)floor($capEuro / $pU);
                $qtyCap = floorToTradeStep($qtyCap, max(1, (int)$a->tradeStep));
                if ($qtyCap <= 0) continue;
                $sellQty = min($qty, $qtyCap);

                applySellTrade($a, $sellQty, $liq, $globalInfo, $ops, 'sell_to_target_from_over +acc ');
                $didAnyTrade = true;

                // stop se abbiamo cassa sufficiente
                $cashAvail = $liq->qty ?? 0.0;
                if ($cashAvail >= $needBuyEuro) break;
            }

            // ricalcolo dopo SELL pass
            $V_tot = recomputeValues($root, $priceCache);
            propagateTargetsAndBands($root, $V_tot, $tolPct);
        }

        // ------ BUY: spendi la cassa sui sotto-band --------------------------
        if (!empty($buyList)) {
            // Ordina BUY per distanza relativa (desc), poi costo unitario più basso
            usort($buyList, function(Asset $a, Asset $b) use ($feeFixed, $feeRate) {
                $ra = relativeTargetGap($a);
                $rb = relativeTargetGap($b);
                if ($ra === $rb) {
                    $ca = unitBuyCostPerEuro($a, $feeFixed, $feeRate);
                    $cb = unitBuyCostPerEuro($b, $feeFixed, $feeRate);
                    return $ca <=> $cb; // meno costo prima
                }
                return $rb <=> $ra; // più lontano prima
            });

            foreach ($buyList as $a) {
                $capEuro = buyCapEuro($a, $tolPct, (float)$globalInfo['accPct']);
                if ($capEuro <= 0.0) continue;

                $qty = findMaxBuyQtyPassingFilters(
                    $a,
                    $liq,
                    $capEuro,
                    $minTrade,
                    $k,
                    $feeFixed,
                    $feeRate
                );
                if ($qty <= 0) {
                    if (!isset($ops[$a->id])) $ops[$a->id] = [0, ''];
                    $ops[$a->id][1] = trim($ops[$a->id][1] . ' insufficient_cash_for_step');
                    continue;
                }

                applyBuyTrade($a, $qty, $liq, $globalInfo, $ops, 'buy_to_target_from_under +acc ');
                $didAnyTrade = true;
            }

            // ricalcolo dopo BUY pass
            $V_tot = recomputeValues($root, $priceCache);
            propagateTargetsAndBands($root, $V_tot, $tolPct);
        }

        // Se non abbiamo fatto nessun trade in questo pass, uscita per evitare loop infinito
        if (!$didAnyTrade) break;
    }

    // --- POST: eventualmente gestire "cash injection con target cash 0"
    // Se nessuno è fuori banda ma c'è cash e target cash=0,
    // potremmo provare un'ultima distribuzione verso target senza superare bandHi.
    // Regola 11: è accettabile lasciare cassa se l'unico acquisto porterebbe fuori banda.
    // (Per semplicità iniziale, lasciamo la cassa: diagnosi in summary.)

    // --- SUMMARY -------------------------------------------------------------
    $cash_after = $liq->qty ?? 0.0;
    $fees_after = $globalInfo['commissionTotal'] ?? 0.0;
    $tax_after  = $globalInfo['taxTotal'] ?? 0.0;

    // conteggi ordini / asset toccati: 1 ordine per asset in ops con delta != 0
    $orders_count = 0;
    foreach ($ops as $id => $pair) {
        if (($pair[0] ?? 0) !== 0) $orders_count++;
    }

    finalizeOpsWithStatuses($root, $tolPct, $ops);

    $summary = [
        'V_before'        => round($V_tot_init, 6),
        'V_after'         => round($root->valueNow, 6),
        'cash_before'     => round($cash_before, 6),
        'cash_after'      => round($cash_after, 6),
        'total_fees'      => round($fees_after, 6),
        'total_taxes'     => round($tax_after, 6),
        'orders_count'    => $orders_count,
        'policy'          => [
            'tol'      => $tolPct,
            'accPct'   => $globalInfo['accPct'],
            'minTrade' => $globalInfo['minTrade'],
            'k'        => $globalInfo['k'],
            'feeFixed' => $globalInfo['feeFixed'],
            'feeRate'  => $globalInfo['feeRate'],
            'taxMode'  => 'gain_only',
            'global_lists' => true,
            'multi_pass'   => true,
        ],
    ];

    return ['ops' => $ops, 'summary' => $summary];
}
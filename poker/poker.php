<?php
/**
 * poker.php  –  Backend API for Nintendo Poker (5-Card Draw)
 *
 * Actions (POST):
 *   new_game  – create a session and return initial game state
 *   deal      – ante up and deal 5 cards to each player
 *   discard   – replace selected cards and advance the phase
 *   new_round – reset deck/hands for the next round
 *   get_state – return sanitised current state
 */

session_start();
header('Content-Type: application/json');

/* ── Constants ──────────────────────────────────────────────────────────── */
const VALUE_RANKS = [
    '2' => 2,  '3' => 3,  '4' => 4,  '5' => 5,  '6' => 6,
    '7' => 7,  '8' => 8,  '9' => 9,  '10' => 10,
    'J' => 11, 'Q' => 12, 'K' => 13, 'A' => 14,
];
const ANTE = 10;

/* ── Deck helpers ───────────────────────────────────────────────────────── */
function createDeck(): array
{
    $deck = [];
    foreach (['S', 'H', 'D', 'C'] as $suit) {
        foreach (array_keys(VALUE_RANKS) as $value) {
            $deck[] = ['suit' => $suit, 'value' => $value];
        }
    }
    shuffle($deck);
    return $deck;
}

function dealCards(array &$deck, int $count = 5): array
{
    return array_splice($deck, 0, $count);
}

/* ── Hand evaluation ────────────────────────────────────────────────────── */
function evaluateHand(array $hand): array
{
    $ranks  = array_map(fn($c) => VALUE_RANKS[$c['value']], $hand);
    $suits  = array_column($hand, 'suit');
    $values = array_column($hand, 'value');

    sort($ranks);

    $isFlush   = count(array_unique($suits)) === 1;
    $valueCounts = array_count_values($values);
    arsort($valueCounts);
    $counts    = array_values($valueCounts);
    $topVals   = array_keys($valueCounts);

    // Detect straight
    $isStraight    = count(array_unique($ranks)) === 5 && (max($ranks) - min($ranks) === 4);
    $aceLowStraight = false;
    if (!$isStraight && in_array(14, $ranks)) {
        $acelow = array_map(fn($r) => $r === 14 ? 1 : $r, $ranks);
        sort($acelow);
        $aceLowStraight = count(array_unique($acelow)) === 5 && (max($acelow) - min($acelow) === 4);
        if ($aceLowStraight) {
            $isStraight = true;
        }
    }

    $hi = max($ranks);

    if ($isFlush && $isStraight) {
        if (!$aceLowStraight && $hi === 14) {
            return ['rank' => 9, 'name' => 'Royal Flush',    'tb' => array_reverse($ranks)];
        }
        return ['rank' => 8, 'name' => 'Straight Flush', 'tb' => array_reverse($ranks)];
    }
    if ($counts[0] === 4) {
        return ['rank' => 7, 'name' => 'Four of a Kind', 'tb' => [VALUE_RANKS[$topVals[0]], VALUE_RANKS[$topVals[1]]]];
    }
    if ($counts[0] === 3 && ($counts[1] ?? 0) === 2) {
        return ['rank' => 6, 'name' => 'Full House',     'tb' => [VALUE_RANKS[$topVals[0]], VALUE_RANKS[$topVals[1]]]];
    }
    if ($isFlush) {
        return ['rank' => 5, 'name' => 'Flush',          'tb' => array_reverse($ranks)];
    }
    if ($isStraight) {
        return ['rank' => 4, 'name' => 'Straight',       'tb' => array_reverse($ranks)];
    }
    if ($counts[0] === 3) {
        $kickers = array_map(fn($v) => VALUE_RANKS[$v], array_slice($topVals, 1));
        rsort($kickers);
        return ['rank' => 3, 'name' => 'Three of a Kind', 'tb' => array_merge([VALUE_RANKS[$topVals[0]]], $kickers)];
    }
    if ($counts[0] === 2 && ($counts[1] ?? 0) === 2) {
        $p1 = VALUE_RANKS[$topVals[0]];
        $p2 = VALUE_RANKS[$topVals[1]];
        $k  = VALUE_RANKS[$topVals[2]];
        return ['rank' => 2, 'name' => 'Two Pair',       'tb' => [max($p1, $p2), min($p1, $p2), $k]];
    }
    if ($counts[0] === 2) {
        $kickers = array_map(fn($v) => VALUE_RANKS[$v], array_slice($topVals, 1));
        rsort($kickers);
        return ['rank' => 1, 'name' => 'One Pair',       'tb' => array_merge([VALUE_RANKS[$topVals[0]]], $kickers)];
    }
    return ['rank' => 0, 'name' => 'High Card',          'tb' => array_reverse($ranks)];
}

/**
 * Returns 0 = tie, 1 = hand1 wins, 2 = hand2 wins.
 */
function compareHands(array $hand1, array $hand2): int
{
    $e1 = evaluateHand($hand1);
    $e2 = evaluateHand($hand2);

    if ($e1['rank'] !== $e2['rank']) {
        return $e1['rank'] > $e2['rank'] ? 1 : 2;
    }
    $tb1 = $e1['tb'];
    $tb2 = $e2['tb'];
    for ($i = 0, $n = min(count($tb1), count($tb2)); $i < $n; $i++) {
        if ($tb1[$i] > $tb2[$i]) {
            return 1;
        }
        if ($tb2[$i] > $tb1[$i]) {
            return 2;
        }
    }
    return 0;
}

/* ── Sanitise game for client ───────────────────────────────────────────── */
/**
 * Returns a client-safe copy of the game state.
 * If $hidePlayerIdx >= 0 that player's hand cards are replaced with nulls.
 */
function sanitiseForClient(array $game, int $hidePlayerIdx = -1): array
{
    $out = [
        'phase'   => $game['phase'],
        'pot'     => $game['pot'],
        'round'   => $game['round'],
        'players' => [],
    ];
    foreach ($game['players'] as $i => $p) {
        $out['players'][$i] = [
            'name'  => $p['name'],
            'chips' => $p['chips'],
            'bet'   => $p['bet'],
            'hand'  => ($i === $hidePlayerIdx)
                       ? array_fill(0, count($p['hand']), null)
                       : $p['hand'],
        ];
    }
    return $out;
}

/* ── Input helpers ──────────────────────────────────────────────────────── */
function postStr(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return htmlspecialchars(trim((string)$v), ENT_QUOTES, 'UTF-8');
}

function postInt(string $key, int $default = 0, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
{
    return max($min, min($max, (int)($_POST[$key] ?? $default)));
}

/* ── Router ─────────────────────────────────────────────────────────────── */
$action = postStr('action', $_GET['action'] ?? '');

switch ($action) {

    /* ── new_game ── */
    case 'new_game':
        $p1Name = postStr('player1') ?: 'Player 1';
        $p2Name = postStr('player2') ?: 'Player 2';
        $chips  = postInt('chips', 1000, 100, 10000);

        $_SESSION['poker'] = [
            'players' => [
                ['name' => $p1Name, 'chips' => $chips, 'hand' => [], 'bet' => 0],
                ['name' => $p2Name, 'chips' => $chips, 'hand' => [], 'bet' => 0],
            ],
            'deck'  => createDeck(),
            'pot'   => 0,
            'phase' => 'deal',
            'round' => 1,
        ];
        echo json_encode(['success' => true, 'game' => sanitiseForClient($_SESSION['poker'])]);
        break;

    /* ── deal ── */
    case 'deal':
        if (!isset($_SESSION['poker'])) {
            echo json_encode(['error' => 'No game in progress']); exit;
        }
        $game = &$_SESSION['poker'];
        if ($game['phase'] !== 'deal') {
            echo json_encode(['error' => 'Cannot deal at this stage']); exit;
        }
        foreach ($game['players'] as &$p) {
            if ($p['chips'] < ANTE) {
                echo json_encode(['error' => $p['name'] . ' has insufficient chips to ante']); exit;
            }
        }
        unset($p);
        foreach ($game['players'] as &$p) {
            $p['chips'] -= ANTE;
            $p['bet']    = ANTE;
        }
        unset($p);
        $game['pot'] = ANTE * count($game['players']);

        foreach ($game['players'] as &$p) {
            $p['hand'] = dealCards($game['deck']);
        }
        unset($p);

        $game['phase'] = 'discard_p1';
        // Show P1's hand; hide P2's
        echo json_encode(['success' => true, 'game' => sanitiseForClient($game, 1)]);
        break;

    /* ── discard ── */
    case 'discard':
        if (!isset($_SESSION['poker'])) {
            echo json_encode(['error' => 'No game in progress']); exit;
        }
        $game = &$_SESSION['poker'];

        $playerIdx = postInt('player', 0, 0, 1);
        $discards  = json_decode($_POST['discards'] ?? '[]', true);
        if (!is_array($discards)) {
            $discards = [];
        }
        // Validate indices: integers 0-4
        $discards = array_values(array_filter(
            $discards,
            fn($i) => is_int($i) && $i >= 0 && $i <= 4
        ));

        $expectedPhase = $playerIdx === 0 ? 'discard_p1' : 'discard_p2';
        if ($game['phase'] !== $expectedPhase) {
            echo json_encode(['error' => 'Not this player\'s turn to discard']); exit;
        }

        // Replace discarded cards with fresh draws
        foreach ($discards as $idx) {
            if (!empty($game['deck'])) {
                $game['players'][$playerIdx]['hand'][$idx] = array_shift($game['deck']);
            }
        }

        $nextPhase      = $playerIdx === 0 ? 'discard_p2' : 'showdown';
        $game['phase']  = $nextPhase;
        $response       = ['success' => true];

        if ($nextPhase === 'showdown') {
            $winnerIdx = compareHands($game['players'][0]['hand'], $game['players'][1]['hand']);
            $eval1     = evaluateHand($game['players'][0]['hand']);
            $eval2     = evaluateHand($game['players'][1]['hand']);

            if ($winnerIdx === 0) {
                $half = intdiv($game['pot'], 2);
                $game['players'][0]['chips'] += $half;
                $game['players'][1]['chips'] += $game['pot'] - $half;
                $winnerName = 'TIE';
            } else {
                $game['players'][$winnerIdx - 1]['chips'] += $game['pot'];
                $winnerName = $game['players'][$winnerIdx - 1]['name'];
            }

            $game['phase']    = 'round_over';
            $response['result'] = [
                'winner'     => $winnerIdx,
                'winnerName' => $winnerName,
                'hand1Name'  => $eval1['name'],
                'hand2Name'  => $eval2['name'],
                'pot'        => $game['pot'],
            ];
            $response['game'] = sanitiseForClient($game); // reveal both hands
        } else {
            // Transition to P2 – show P2's hand once P2 takes the device; hide P1
            $response['game'] = sanitiseForClient($game, 0);
        }
        echo json_encode($response);
        break;

    /* ── new_round ── */
    case 'new_round':
        if (!isset($_SESSION['poker'])) {
            echo json_encode(['error' => 'No game in progress']); exit;
        }
        $game = &$_SESSION['poker'];
        foreach ($game['players'] as $p) {
            if ($p['chips'] <= 0) {
                echo json_encode(['error' => 'Game over – a player has no chips']); exit;
            }
        }
        $game['deck']  = createDeck();
        $game['pot']   = 0;
        $game['phase'] = 'deal';
        $game['round']++;
        foreach ($game['players'] as &$p) {
            $p['hand'] = [];
            $p['bet']  = 0;
        }
        unset($p);
        echo json_encode(['success' => true, 'game' => sanitiseForClient($game)]);
        break;

    /* ── get_state ── */
    case 'get_state':
        if (!isset($_SESSION['poker'])) {
            echo json_encode(['error' => 'No game in progress']); exit;
        }
        echo json_encode(['success' => true, 'game' => sanitiseForClient($_SESSION['poker'])]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}

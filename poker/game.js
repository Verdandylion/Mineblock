/**
 * game.js  –  Nintendo Poker front-end logic
 *
 * Communicates with poker.php via fetch() / POST.
 * Screens: start → game → result (repeat from game).
 */

'use strict';

const API = 'poker.php';

/** Symbol map for server suit codes. */
const SUITS = { S: '♠', H: '♥', D: '♦', C: '♣' };

/** Call the PHP API. Returns parsed JSON or throws. */
async function api(action, data = {}) {
  const body = new URLSearchParams({ action, ...data });
  const res  = await fetch(API, { method: 'POST', body });
  if (!res.ok) throw new Error(`Server error ${res.status}`);
  return res.json();
}

/** Show exactly one screen. */
function showScreen(id) {
  document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
  document.getElementById(id).classList.add('active');
}

/**
 * Build a button element and attach a click listener.
 * Avoids inline onclick attributes.
 */
function makeBtn(label, cssClass, handler) {
  const btn = document.createElement('button');
  btn.className = `btn ${cssClass}`;
  btn.textContent = label;
  btn.addEventListener('click', handler);
  return btn;
}

/** Replace the action-button bar with the given button elements. */
function setBtns(...buttons) {
  const container = document.getElementById('action-btns');
  container.innerHTML = '';
  buttons.forEach(b => container.appendChild(b));
}

/** Update the header round indicator. */
function setRound(round) {
  const el = document.getElementById('header-round');
  el.textContent = round ? `ROUND ${round}` : '';
}

/* ── Card rendering ───────────────────────────────────────────────────────── */

/**
 * Build HTML for a single card object  { suit, value }  or null (face-down).
 * selectable = true adds a data-idx attribute for click-toggling.
 */
function cardHTML(card, idx, selectable) {
  if (!card) {
    return '<div class="card face-down"></div>';
  }
  const sym    = SUITS[card.suit] ?? card.suit;
  const isRed  = card.suit === 'H' || card.suit === 'D';
  const color  = isRed ? 'red' : 'black';
  const attrs  = selectable ? ` data-idx="${idx}"` : '';
  return `
    <div class="card ${color}"${attrs}>
      <div class="card-corner tl">${card.value}<br>${sym}</div>
      <div class="card-center">${sym}</div>
      <div class="card-corner br">${card.value}<br>${sym}</div>
    </div>`.trim();
}

function handHTML(hand, selectable = false) {
  return hand.map((c, i) => cardHTML(c, i, selectable)).join('');
}

/* ── Game screen rendering ────────────────────────────────────────────────── */

/** Cached game state between turns. */
let gameState = null;

/**
 * Render the game table.
 *
 * @param {object} game          – sanitised game object from server
 * @param {number} activePlayer  – 0 or 1: whose hand is at the bottom (selectable)
 *                                 -1: nobody (informational display only)
 */
function renderGame(game, activePlayer = -1) {
  gameState = game;
  setRound(game.round);

  // "Current" player sits at the bottom; opponent at the top.
  const curIdx = activePlayer >= 0 ? activePlayer : 0;
  const oppIdx = curIdx === 0 ? 1 : 0;
  const cur    = game.players[curIdx];
  const opp    = game.players[oppIdx];

  // Opponent row (top)
  document.getElementById('opp-name').textContent  = opp.name;
  document.getElementById('opp-chips').textContent = opp.chips;
  document.getElementById('opp-hand').innerHTML    = handHTML(opp.hand, false);

  // Pot
  document.getElementById('pot-amount').textContent = game.pot;

  // Current player row (bottom)
  document.getElementById('cur-name').textContent  = cur.name;
  document.getElementById('cur-chips').textContent = cur.chips;
  document.getElementById('cur-hand').innerHTML    = handHTML(cur.hand, activePlayer >= 0);

  // Wire up card-click toggling when the hand is selectable
  if (activePlayer >= 0) {
    document.querySelectorAll('#cur-hand .card:not(.face-down)').forEach(el => {
      el.addEventListener('click', () => el.classList.toggle('selected'));
    });
  }
}

/** Get indices of cards marked for discard. */
function selectedIndices() {
  return [...document.querySelectorAll('#cur-hand .card.selected')]
    .map(el => parseInt(el.dataset.idx, 10));
}

/* ── Message / button helpers ─────────────────────────────────────────────── */

function setMsg(text, highlight = false) {
  const el = document.getElementById('action-msg');
  el.textContent = text;
  el.classList.toggle('highlight', highlight);
}

/* ══ GAME FLOW ════════════════════════════════════════════════════════════════ */

/** Called when the Start Game form is submitted. */
async function startGame(e) {
  e.preventDefault();
  const p1    = document.getElementById('p1-name').value.trim()  || 'Player 1';
  const p2    = document.getElementById('p2-name').value.trim()  || 'Player 2';
  const chips = document.getElementById('start-chips').value     || 1000;
  try {
    const res = await api('new_game', { player1: p1, player2: p2, chips });
    if (res.error) { alert(res.error); return; }
    gameState = res.game;
    showScreen('game-screen');
    await doDeal();
  } catch (err) {
    alert('Connection error: ' + err.message);
  }
}

/** Deal cards and present Player 1's discard turn. */
async function doDeal() {
  try {
    const res = await api('deal');
    if (res.error) { alert(res.error); return; }
    const p1name = res.game.players[0].name;
    renderGame(res.game, 0);
    setMsg(`${p1name} – tap cards to mark them for discard, then press DRAW.`);
    setBtns(makeBtn('► Draw Cards', 'btn-primary', () => doDiscard(0)));
  } catch (err) {
    alert('Connection error: ' + err.message);
  }
}

/** Send discard request for the given player index. */
async function doDiscard(playerIdx) {
  const indices = selectedIndices();
  try {
    const res = await api('discard', {
      player:   playerIdx,
      discards: JSON.stringify(indices),
    });
    if (res.error) { alert(res.error); return; }

    if (res.result) {
      // Both players have drawn – show result
      showResult(res.result, res.game);
    } else {
      // Transition to Player 2 – hide all cards while passing the device
      gameState = res.game;
      document.getElementById('cur-hand').innerHTML  = hiddenHandMsg();
      document.getElementById('opp-hand').innerHTML  = hiddenHandMsg();
      const p2name = res.game.players[1].name;
      setMsg(`Pass the device to ${p2name}!`, true);
      setBtns(makeBtn(`→ ${p2name}'s Turn`, 'btn-secondary', beginP2Turn));
    }
  } catch (err) {
    alert('Connection error: ' + err.message);
  }
}

function hiddenHandMsg() {
  return `<div style="font-size:8px;color:rgba(255,255,255,0.25);padding:20px;letter-spacing:1px;">— HIDDEN —</div>`;
}

/** Present Player 2's discard turn after receiving the device. */
function beginP2Turn() {
  const p2name = gameState.players[1].name;
  renderGame(gameState, 1);
  setMsg(`${p2name} – tap cards to mark them for discard, then press DRAW.`);
  setBtns(makeBtn('► Draw Cards', 'btn-primary', () => doDiscard(1)));
}

/* ══ RESULT SCREEN ═══════════════════════════════════════════════════════════ */

function showResult(result, game) {
  showScreen('result-screen');
  setRound(game.round);

  const p1 = game.players[0];
  const p2 = game.players[1];

  // Banner
  const banner = result.winner === 0
    ? "IT'S A TIE!"
    : `${result.winnerName} WINS!`;
  document.getElementById('winner-banner').textContent = banner;

  // Render both hands
  renderResultHand('rh1', p1, result.hand1Name, result.winner === 1);
  renderResultHand('rh2', p2, result.hand2Name, result.winner === 2);

  // Scoreboard
  document.getElementById('sc1-name').textContent  = p1.name;
  document.getElementById('sc1-chips').textContent = p1.chips;
  document.getElementById('sc2-name').textContent  = p2.name;
  document.getElementById('sc2-chips').textContent = p2.chips;

  // Pot info
  document.getElementById('result-pot').textContent = `POT WAS ${result.pot} CHIPS`;

  // Game-over check
  const canContinue = p1.chips > 0 && p2.chips > 0;
  document.getElementById('next-round-btn').style.display = canContinue ? '' : 'none';
  const goMsg = document.getElementById('game-over-msg');
  if (!canContinue) {
    const brokeName = p1.chips <= 0 ? p1.name : p2.name;
    goMsg.textContent = `${brokeName} IS BROKE — GAME OVER!`;
    goMsg.style.display = '';
  } else {
    goMsg.style.display = 'none';
  }
}

function renderResultHand(panelId, player, handName, isWinner) {
  const panel = document.getElementById(panelId);
  panel.classList.toggle('winner-hand', isWinner);
  panel.innerHTML = `
    <div class="rh-name">${escHtml(player.name)}</div>
    <div class="hand">${handHTML(player.hand)}</div>
    <div class="rh-hand-name">${escHtml(handName)}</div>
  `;
}

/** Start the next round. */
async function nextRound() {
  try {
    const res = await api('new_round');
    if (res.error) { alert(res.error); return; }
    gameState = res.game;
    showScreen('game-screen');
    await doDeal();
  } catch (err) {
    alert('Connection error: ' + err.message);
  }
}

/** Return to the start screen. */
function newGame() {
  showScreen('start-screen');
}

/* ── Utility ──────────────────────────────────────────────────────────────── */

function escHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

/* ── Init ─────────────────────────────────────────────────────────────────── */

document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('start-form').addEventListener('submit', startGame);
  document.getElementById('next-round-btn').addEventListener('click', nextRound);
  document.getElementById('new-game-btn').addEventListener('click', newGame);
  showScreen('start-screen');
});

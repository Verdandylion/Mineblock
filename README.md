# Mineblock
A pixelated voxel game with color and size customization.

---

## 🃏 Nintendo Poker

A Nintendo-styled **5-Card Draw** poker game for two players — pass-and-play on a single device.

### Features
- **5-Card Draw** rules — each player discards up to 5 cards and draws replacements
- **Full hand evaluation** — Royal Flush through High Card, with accurate tiebreakers
- **Chip tracking** across multiple rounds (starting chips configurable)
- **Nintendo aesthetic** — Press Start 2P pixel font, felt table, retro buttons, animated winner banner
- **Privacy-safe pass-and-play** — each player's hand is hidden while the device is handed over

### How to run

You need a PHP server (PHP 7.4+) to serve the poker game.

**Option A – PHP built-in server** (quickest for local testing):
```bash
cd poker
php -S localhost:8080
```
Then open <http://localhost:8080> in your browser.

**Option B – Any web host / LAMP / XAMPP / Laragon**  
Copy the `poker/` folder into your web root and navigate to `poker/index.html`.

### How to play
1. Enter both player names and choose starting chips, then press **START GAME**.
2. Each round starts with an automatic **10-chip ante** from both players.
3. **Player 1** sees their hand — tap cards to mark them for discard, then press **Draw Cards**.
4. Pass the device to **Player 2** and press their button to reveal their hand.
5. **Player 2** selects their discards and draws.
6. The best 5-card hand wins the pot. Press **Next Round** to continue, or **New Game** to restart.

### Hand rankings (best → worst)
| Rank | Hand |
|------|------|
| 9 | Royal Flush |
| 8 | Straight Flush |
| 7 | Four of a Kind |
| 6 | Full House |
| 5 | Flush |
| 4 | Straight |
| 3 | Three of a Kind |
| 2 | Two Pair |
| 1 | One Pair |
| 0 | High Card |

### File structure
```
poker/
  index.html   – game UI (three screens: start / game / result)
  style.css    – Nintendo-themed styles
  game.js      – front-end logic (fetch API, card rendering, screen flow)
  poker.php    – PHP backend (session-based game state, deal, discard, hand eval)
```
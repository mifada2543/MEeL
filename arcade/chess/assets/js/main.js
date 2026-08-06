import { SVG_PIECES, UNICODE_PIECES, UNICODE_PIECES_WHITE } from "./assets.js";
import { sounds } from "./audio.js";
import { ChessGame } from "./engine.js";
import {
  saveMoveAPI,
  fetchMovesAPI,
  checkRoomStatusAPI,
  createRoomAPI,
  joinRoomAPI,
  sendGameActionAPI,
} from "./api.js";
const game = new ChessGame();
// State Variables
let roomCode = null;
let myColor = null;
let lastMoveId = 0;
let pollingTimer = null;
let roomStatusTimer = null;
let suppressNetworkSync = false;
let localBoardFlipped = false;
let pendingCaptureSvg = null;
// State resign & draw (multiplayer)
let drawOfferPending = false; // tawaran seri saya sedang menunggu jawaban
let drawModalShown = false; // modal terima/tolak seri sedang terbuka
// State rematch (tanding ulang, multiplayer)
let rematchOfferPending = false; // tawaran tanding ulang saya sedang menunggu jawaban
let rematchModalShown = false; // modal terima/tolak tanding ulang sedang terbuka
let rematchLeaving = false; // sedang keluar dari sesi online (redirect 5 dtk) — kunci tombol
// Referensi ke leaveRoom() (didefinisikan di dalam DOMContentLoaded) — dipakai
// fungsi top-level saat keluar dari sesi online ke mode "Lawan Rakan".
let exitToLocalModeFn = null;
let opponentJoined = false; // lawan sudah bergabung (tombol seri/menyerah baru muncul setelah ada langkah)
// State deteksi lawan terputus (dari flag opponent_online di get_move.php)
let opponentOnline = true; // status koneksi lawan (versi terakhir dari server)
let opponentOfflineNotified = false; // sudah pernah menampilkan modal terputus?
let opponentOfflineNextPromptAt = 0; // kapan boleh prompt ulang (ms) — anti spam modal
let opponentOfflineModalOpen = false; // modal klaim kemenangan sedang terbuka
let gameOverEventSent = false; // event game_over sudah dikirim ke server (dedup)
// DOM Elements
const boardEl = document.getElementById("chess-board");
const moveHistoryList = document.getElementById("move-history-list");
const promotionModal = document.getElementById("promotion-modal");
const blackName = document.getElementById("player-black-name");
// ── Color Picker Overlay (multiplayer: pilih warna sebelum game dimulai) ──
const colorPickerOverlay = document.getElementById("color-picker-overlay");
const cpPick = document.getElementById("cp-pick");
const cpWaiting = document.getElementById("cp-waiting");
const cpRoomCode = document.getElementById("cp-room-code");
const btnPickWhite = document.getElementById("btn-pick-white");
const btnPickBlack = document.getElementById("btn-pick-black");
const btnCancelWaiting = document.getElementById("btn-cancel-waiting");
let colorPickerHideTimer = null; // cegah race hide/show overlay
function resetAllModes() {
  const panel = document.getElementById("multiplayer-panel");
  if (panel) panel.classList.add("hidden");
  const buttons = [
    document.getElementById("mode-vs-online"),
    document.getElementById("mode-vs-local"),
    document.getElementById("mode-vs-ai"),
  ];
  buttons.forEach((btn) => {
    if (!btn) return;
    btn.className =
      "flex items-center justify-between p-3.5 rounded-xl border border-slate-700/80 hover:border-slate-500 hover:bg-slate-800/50 text-left transition-all duration-200 active:scale-[0.98] w-full group";
    const indicator = btn.querySelector(".mode-indicator");
    if (indicator) {
      indicator.innerHTML = "";
      indicator.className =
        "mode-indicator w-3.5 h-3.5 rounded-full border-2 border-slate-600 transition-colors";
    }
  });
}
function updateRoomUI() {
  const panel = document.getElementById("multiplayer-panel");
  const badge = document.getElementById("room-status-badge");
  if (!panel || !badge) return;
  if (game.gameMode === "online") {
    panel.classList.remove("hidden");
    badge.className =
      "px-3 py-1 text-[10px] font-bold rounded-full bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 uppercase tracking-widest";
    badge.innerText = roomCode ? "Online" : "Offline";
  } else {
    panel.classList.add("hidden");
    badge.className =
      "px-3 py-1 text-[10px] font-bold rounded-full bg-slate-500/10 text-slate-400 border border-slate-500/20 uppercase tracking-widest";
    badge.innerText = "Offline";
  }
}
// ── COLOR PICKER OVERLAY ───────────────────────────────────────────────────
function showColorPicker() {
  if (!colorPickerOverlay) return;
  // Batalkan timer hide yang masih tertunda agar overlay tidak ke-hide lagi.
  if (colorPickerHideTimer) {
    clearTimeout(colorPickerHideTimer);
    colorPickerHideTimer = null;
  }
  colorPickerOverlay.classList.remove("hidden");
  setTimeout(() => colorPickerOverlay.classList.remove("opacity-0"), 50);
  if (cpPick) cpPick.classList.remove("hidden");
  if (cpWaiting) cpWaiting.classList.add("hidden");
}
function hideColorPicker() {
  if (!colorPickerOverlay) return;
  colorPickerOverlay.classList.add("opacity-0");
  if (colorPickerHideTimer) clearTimeout(colorPickerHideTimer);
  colorPickerHideTimer = setTimeout(() => {
    colorPickerOverlay.classList.add("hidden");
    colorPickerHideTimer = null;
    // Bug: tombol 'Tawarkan Seri'/'Mengalah' macet disabled saat game dimulai.
    // updateActionButtons() terakhir dievaluasi ketika overlay masih terlihat
    // (waiting=true → disabled), dan tidak ada yang memanggil ulang setelah
    // overlay selesai menghilang. Panggil ulang sekarang agar tombol aktif
    // begitu game benar-benar dimulai (giliran pemain sesuai).
    updateActionButtons();
  }, 300);
}
function showWaitingState(code) {
  if (cpRoomCode) cpRoomCode.innerText = code;
  if (cpPick) cpPick.classList.add("hidden");
  if (cpWaiting) cpWaiting.classList.remove("hidden");
}
async function syncRoomState(resetBoard = false) {
  if (!roomCode) return;
  const data = await fetchMovesAPI(roomCode, 0);
  const moves = data.moves;
  // Re-sync: ambil status koneksi lawan terbaru, jangan munculkan modal.
  if (typeof data.opponentOnline === "boolean") {
    opponentOnline = data.opponentOnline;
    opponentOfflineNotified = false;
    opponentOfflineNextPromptAt = 0;
  }
  suppressNetworkSync = true;
  game.muteSounds = true;
  if (resetBoard) game.reset();
  let terminalResult = null;
  for (const move of moves) {
    lastMoveId = move.id;
    const payload =
      typeof move.move_data === "string"
        ? JSON.parse(move.move_data)
        : move.move_data;
    if (payload.type) {
      // silent: saat sync jangan memunculkan modal tawaran seri.
      const evResult = handleGameEvent(payload, true);
      if (evResult) terminalResult = evResult;
      continue;
    }
    game.executeMove(
      payload.fromR,
      payload.fromC,
      payload.toR,
      payload.toC,
      payload.promotedPieceType || null,
    );
  }
  suppressNetworkSync = false;
  game.muteSounds = false;
  renderBoard();
  updateGameStatus(terminalResult);
}
function startPolling() {
  stopPolling();
  if (!roomCode) return;
  pollingTimer = setInterval(async () => {
    try {
      const data = await fetchMovesAPI(roomCode, lastMoveId);
      handleOpponentStatus(data.opponentOnline);
      const moves = data.moves;
      if (!moves.length) return;
      suppressNetworkSync = true;
      game.muteSounds = true;
      let hasNew = false;
      let lastResult = null;
      for (const move of moves) {
        lastMoveId = move.id;
        const payload =
          typeof move.move_data === "string"
            ? JSON.parse(move.move_data)
            : move.move_data;
        // Event non-langkah (resign / draw) — bukan gerakan biasa.
        if (payload.type) {
          const evResult = handleGameEvent(payload);
          if (evResult) lastResult = evResult;
          hasNew = true;
          continue;
        }
        if (payload.color === myColor) continue;
        const result = game.executeMove(
          payload.fromR,
          payload.fromC,
          payload.toR,
          payload.toC,
          payload.promotedPieceType || null,
        );
        if (result && result !== "promotion") lastResult = result;
        notifyGameOver(result);
        hasNew = true;
      }
      suppressNetworkSync = false;
      game.muteSounds = false;
      if (hasNew) {
        if (lastResult) sounds.init();
        renderBoard();
        updateGameStatus(lastResult);
      }
    } catch (err) {
      suppressNetworkSync = false;
      game.muteSounds = false;
      console.error("Polling error:", err);
    }
  }, 500);
}
function stopPolling() {
  if (pollingTimer) {
    clearInterval(pollingTimer);
    pollingTimer = null;
  }
}
function tungguLawanBergabung(code) {
  if (roomStatusTimer) clearInterval(roomStatusTimer);
  roomStatusTimer = setInterval(async () => {
    try {
      const data = await checkRoomStatusAPI(code);
      if (data.success && data.joined) {
        clearInterval(roomStatusTimer);
        roomStatusTimer = null;
        opponentJoined = true; // lawan bergabung — tombol seri/menyerah siap muncul
        hideColorPicker(); // game dimulai — papan aktif & bisa diklik
        document.getElementById("room-status").innerText =
          "Lawan bergabung! Menunggu langkah...";
        // #player-black-name TIDAK ada di HTML arcade/chess/index.php —
        // akses tanpa guard melempar TypeError & membatalkan startPolling()
        // (langkah lawan tidak pernah ter-poll). Guard sama seperti tempat lain.
        if (blackName) blackName.innerText = "Pemain Hitam (Online)";
        startPolling();
      }
    } catch (err) {
      console.error("Error mengecek status room:", err);
    }
  }, 2000);
}
// RENDERING BOARD
function isBoardFlipped() {
  if (game.gameMode === "online") return myColor === "b";
  if (game.gameMode === "local") return localBoardFlipped;
  return false;
}
function viewToBoard(viewR, viewC) {
  if (!isBoardFlipped()) return { r: viewR, c: viewC };
  return { r: 7 - viewR, c: 7 - viewC };
}
function createBoardCell(viewR, viewC) {
  const cell = document.createElement("div");
  cell.dataset.row = viewR;
  cell.dataset.col = viewC;
  const isDark = (viewR + viewC) % 2 === 1;
  cell.className =
    "relative w-full aspect-square flex items-center justify-center transition-all duration-200";
  cell.style.backgroundColor = isDark ? "#769656" : "#EEEED2";
  return { cell, isDark };
}
function renderBoard() {
  boardEl.innerHTML = "";
  boardEl.style.transform = "";
  for (let viewR = 0; viewR < 8; viewR++) {
    for (let viewC = 0; viewC < 8; viewC++) {
      const { r: boardR, c: boardC } = viewToBoard(viewR, viewC);
      const { cell, isDark } = createBoardCell(viewR, viewC);
      if (game.lastMove) {
        const { from, to } = game.lastMove;
        if (
          (from.r === boardR && from.c === boardC) ||
          (to.r === boardR && to.c === boardC)
        ) {
          cell.style.backgroundColor = isDark ? "#BACA44" : "#F6F682";
        }
      }
      if (
        game.activeSquare &&
        game.activeSquare.r === boardR &&
        game.activeSquare.c === boardC
      ) {
        cell.style.backgroundColor = "#F6F669";
      }
      const isValidMove = game.validMoves.some(
        (m) => m.r === boardR && m.c === boardC,
      );
      const pieceAtCell = game.getPiece(boardR, boardC);
      if (isValidMove) {
        const marker = document.createElement("div");
        if (pieceAtCell)
          marker.className =
            "absolute w-5/6 h-5/6 border-4 border-rose-500/70 rounded-full z-20 pointer-events-none animate-pulse";
        else
          marker.className =
            "w-[10px] h-[10px] rounded-full bg-black/45 z-20 pointer-events-none";
        cell.appendChild(marker);
      }
      if (pieceAtCell) {
        const pieceMarkup = SVG_PIECES[pieceAtCell.color + pieceAtCell.type];
        if (pieceMarkup) {
          const isJustPlaced =
            game.lastMove &&
            game.lastMove.to.r === boardR &&
            game.lastMove.to.c === boardC;
          // Determine animation class
          let animClass = "";
          if (isJustPlaced) {
            if (game.lastMoveType === "capture") {
              animClass = "piece-capture-land";
            } else if (game.lastMoveType === "castle") {
              animClass = "piece-castle";
            } else if (game.lastMoveType === "promotion") {
              animClass = "piece-promote";
            } else {
              animClass = "piece-anim";
            }
          }
          // Ghost overlay
          if (game.lastCapturedPiece && pendingCaptureSvg) {
            const isCapturePos =
              game.lastCapturedPiece.r === boardR &&
              game.lastCapturedPiece.c === boardC;
            if (isCapturePos) {
              const ghostEl = document.createElement("div");
              ghostEl.className = `absolute inset-0 flex items-center justify-center z-20 select-none pointer-events-none piece-captured`;
              ghostEl.innerHTML = pendingCaptureSvg;
              cell.appendChild(ghostEl);
            }
          }
          const pieceWrapper = document.createElement("div");
          pieceWrapper.className = `absolute inset-0 flex items-center justify-center z-10 select-none pointer-events-none ${animClass}`;
          pieceWrapper.innerHTML = pieceMarkup;
          cell.appendChild(pieceWrapper);
        }
      }
      // ===== NAVIGATION COORDINATES =====
      if (viewC === 0) {
        const rankLabel = document.createElement("span");
        rankLabel.style.cssText = `position:absolute;top:2px;left:3px;font-size:10px;font-weight:700;z-index:30;pointer-events:none;user-select:none;line-height:1;color:${isDark ? "rgba(238,238,210,0.85)" : "rgba(118,150,86,0.85)"}`;
        rankLabel.innerText = 8 - boardR;
        cell.appendChild(rankLabel);
      }
      if (viewC === 7) {
        const rankLabelRight = document.createElement("span");
        rankLabelRight.style.cssText = `position:absolute;bottom:2px;right:3px;font-size:9px;font-weight:700;z-index:30;pointer-events:none;user-select:none;line-height:1;color:${isDark ? "rgba(238,238,210,0.6)" : "rgba(118,150,86,0.6)"}`;
        rankLabelRight.innerText = 8 - boardR;
        cell.appendChild(rankLabelRight);
      }
      if (viewR === 0) {
        const fileLabelTop = document.createElement("span");
        fileLabelTop.style.cssText = `position:absolute;top:2px;right:3px;font-size:9px;font-weight:700;z-index:30;pointer-events:none;user-select:none;line-height:1;color:${isDark ? "rgba(238,238,210,0.6)" : "rgba(118,150,86,0.6)"}`;
        fileLabelTop.innerText = String.fromCharCode(97 + boardC);
        cell.appendChild(fileLabelTop);
      }
      if (viewR === 7) {
        const fileLabel = document.createElement("span");
        fileLabel.style.cssText = `position:absolute;bottom:2px;left:3px;font-size:10px;font-weight:700;z-index:30;pointer-events:none;user-select:none;line-height:1;color:${isDark ? "rgba(238,238,210,0.85)" : "rgba(118,150,86,0.85)"}`;
        fileLabel.innerText = String.fromCharCode(97 + boardC);
        cell.appendChild(fileLabel);
      }
      cell.addEventListener("click", () => handleCellClick(boardR, boardC));
      boardEl.appendChild(cell);
    }
  }
  clearAnimationState();
}
function flipBoardWithAnimation() {
  boardEl.style.pointerEvents = "none";
  boardEl.classList.add("board-flip-out");
  setTimeout(() => {
    boardEl.classList.remove("board-flip-out");
    renderBoard();
    void boardEl.offsetWidth;
    boardEl.classList.add("board-flip-in");
    setTimeout(() => {
      boardEl.classList.remove("board-flip-in");
      boardEl.style.pointerEvents = "";
    }, 280);
  }, 200);
}
function clearAnimationState() {
  setTimeout(() => {
    game.lastCapturedPiece = null;
    game.lastMoveType = null;
    pendingCaptureSvg = null;
  }, 500);
}
function handleCellClick(r, c) {
  if (game.isGameOver) return;
  // Multiplayer: papan terkunci selama overlay pilih warna / menunggu lawan.
  if (
    game.gameMode === "online" &&
    colorPickerOverlay &&
    !colorPickerOverlay.classList.contains("hidden")
  )
    return;
  if (game.gameMode === "ai" && game.turn === "b") return;
  if (game.gameMode === "online" && myColor && game.turn !== myColor) return;
  const clickedPiece = game.getPiece(r, c);
  if (game.activeSquare) {
    const isPossibleMove = game.validMoves.some((m) => m.r === r && m.c === c);
    if (isPossibleMove) {
      const fromPiece = game.getPiece(game.activeSquare.r, game.activeSquare.c);
      const targetPiece = game.getPiece(r, c);
      const enPassantMove = game.validMoves.find(
        (m) => m.r === r && m.c === c && m.isEnPassant,
      );
      if (targetPiece) {
        pendingCaptureSvg =
          SVG_PIECES[targetPiece.color + targetPiece.type] || null;
      } else if (enPassantMove) {
        const epPiece = game.getPiece(game.activeSquare.r, c);
        if (epPiece)
          pendingCaptureSvg = SVG_PIECES[epPiece.color + epPiece.type] || null;
      } else {
        pendingCaptureSvg = null;
      }
      const result = game.executeMove(
        game.activeSquare.r,
        game.activeSquare.c,
        r,
        c,
      );
      if (result === "promotion") {
        showPromotionModal();
        return;
      }
      updateGameStatus(result);
      if (game.gameMode === "online" && !suppressNetworkSync && roomCode) {
        saveMoveAPI(roomCode, game.history[game.history.length - 1]).then(
          (d) => {
            if (d.success) lastMoveId = d.id;
            // Kirim game_over SETELAH langkah terminal tersimpan di DB agar
            // validasi server (pecundang vs langkah terakhir) benar.
            notifyGameOver(result);
          },
        );
      } else {
        // Non-online (no-op) atau online tanpa save (tidak pernah terminal).
        notifyGameOver(result);
      }
      if (game.gameMode === "local" && !game.isGameOver) {
        localBoardFlipped = !localBoardFlipped;
        flipBoardWithAnimation();
      } else {
        renderBoard();
      }
      if (game.gameMode === "ai" && !game.isGameOver && game.turn === "b")
        setTimeout(triggerAiMove, 800);
    } else if (clickedPiece && clickedPiece.color === game.turn) {
      pendingCaptureSvg = null;
      game.activeSquare = { r, c };
      game.validMoves = game.getValidMoves(r, c);
      renderBoard();
    } else {
      pendingCaptureSvg = null;
      game.activeSquare = null;
      game.validMoves = [];
      renderBoard();
    }
  } else {
    pendingCaptureSvg = null;
    if (clickedPiece && clickedPiece.color === game.turn) {
      game.activeSquare = { r, c };
      game.validMoves = game.getValidMoves(r, c);
      renderBoard();
    }
  }
}
function triggerAiMove() {
  if (game.isGameOver || game.turn !== "b" || game.gameMode !== "ai") return;
  const aiDecision = game.getBestMove();
  if (aiDecision) {
    // Simpan SVG piece yang ditangkap oleh AI SEBELUM executeMove
    const aiTarget = game.getPiece(aiDecision.to.r, aiDecision.to.c);
    const isEnPassant = aiDecision.to && aiDecision.to.isEnPassant;
    if (aiTarget) {
      pendingCaptureSvg = SVG_PIECES[aiTarget.color + aiTarget.type] || null;
    } else if (isEnPassant) {
      const epPiece = game.getPiece(aiDecision.from.r, aiDecision.to.c);
      pendingCaptureSvg = epPiece
        ? SVG_PIECES[epPiece.color + epPiece.type] || null
        : null;
    } else {
      pendingCaptureSvg = null;
    }
    const result = game.executeMove(
      aiDecision.from.r,
      aiDecision.from.c,
      aiDecision.to.r,
      aiDecision.to.c,
    );
    if (result === "promotion") {
      const pending = game.promotionPending;
      if (pending) {
        const promResult = game.executeMove(
          pending.from.r,
          pending.from.c,
          pending.to.r,
          pending.to.c,
          "q",
        );
        game.promotionPending = null;
        renderBoard();
        updateGameStatus(
          promResult && promResult !== "promotion"
            ? promResult
            : { status: "success", check: game.isKingInCheck("w") },
        );
      }
    } else {
      renderBoard();
      updateGameStatus(result);
    }
  }
}
function showPromotionModal() {
  const choices = document.getElementById("promotion-choices");
  choices.innerHTML = "";
  const options = ["q", "r", "b", "n"];
  const activeColor = game.turn;
  options.forEach((opt) => {
    const btn = document.createElement("button");
    btn.className =
      "bg-slate-800 hover:bg-slate-700 p-4 border border-slate-600 rounded-xl flex items-center justify-center transition-all active:scale-95 shadow-md";
    btn.innerHTML = SVG_PIECES[activeColor + opt];
    btn.onclick = () => {
      const pending = game.promotionPending;
      if (pending) {
        const result = game.executeMove(
          pending.from.r,
          pending.from.c,
          pending.to.r,
          pending.to.c,
          opt,
        );
        game.promotionPending = null;
        promotionModal.classList.add("hidden");
        updateGameStatus(result);
        if (game.gameMode === "online" && !suppressNetworkSync && roomCode) {
          saveMoveAPI(roomCode, game.history[game.history.length - 1]).then(
            (d) => {
              if (d.success) lastMoveId = d.id;
              notifyGameOver(result);
            },
          );
        } else {
          notifyGameOver(result);
        }
        if (game.gameMode === "local" && !game.isGameOver) {
          localBoardFlipped = !localBoardFlipped;
          flipBoardWithAnimation();
        } else {
          renderBoard();
        }
        if (game.gameMode === "ai" && !game.isGameOver && game.turn === "b")
          setTimeout(triggerAiMove, 800);
      }
    };
    choices.appendChild(btn);
  });
  promotionModal.classList.remove("hidden");
}
function updateGameStatus(result) {
  document.getElementById("captured-white").innerHTML = game.captured.b
    .map(
      (p) =>
        `<span class="text-slate-300 drop-shadow">${UNICODE_PIECES_WHITE[p] || p}</span>`,
    )
    .join("");
  document.getElementById("captured-black").innerHTML = game.captured.w
    .map(
      (p) =>
        `<span class="text-emerald-500/80 drop-shadow">${UNICODE_PIECES[p] || p}</span>`,
    )
    .join("");
  moveHistoryList.innerHTML = "";
  const placeholder = document.getElementById("no-moves-placeholder");
  if (game.history.length === 0) placeholder.classList.remove("hidden");
  else {
    placeholder.classList.add("hidden");
    const lastMoveIdx = game.history.length - 1;
    for (let i = 0; i < game.history.length; i += 2) {
      const wMove = game.history[i]?.algebraic || "";
      const bMove = game.history[i + 1]?.algebraic || "";
      const moveNum = Math.floor(i / 2) + 1;
      const isCurrent = i === lastMoveIdx || i + 1 === lastMoveIdx;
      const rowClass = isCurrent
        ? "bg-emerald-500/10 border-l-2 border-emerald-500"
        : "hover:bg-slate-800/30";
      moveHistoryList.innerHTML += `
        <tr class="${rowClass} transition-colors">
          <td class="py-1.5 px-1 text-xs text-slate-500 font-bold text-center w-8">${moveNum}.</td>
          <td class="py-1.5 px-2 text-sm font-semibold ${i === lastMoveIdx ? "text-emerald-300" : "text-slate-200"}">${wMove}</td>
          <td class="py-1.5 px-2 text-sm font-semibold ${i + 1 === lastMoveIdx ? "text-emerald-300" : "text-slate-200"}">${bMove}</td>
        </tr>`;
    }
    const container = document.getElementById("move-history-container");
    container.scrollTop = container.scrollHeight;
  }
  const modePanel = document.getElementById("game-mode-panel");
  if (modePanel) {
    if (game.history.length > 0) {
      modePanel.classList.add("hidden");
    } else {
      modePanel.classList.remove("hidden");
    }
  }
  document
    .getElementById("check-alert-white")
    .classList.toggle("hidden", !game.isKingInCheck("w"));
  document
    .getElementById("check-alert-black")
    .classList.toggle("hidden", !game.isKingInCheck("b"));
  const indColor = document.getElementById("turn-indicator-color");
  const indText = document.getElementById("turn-indicator-text");
  if (game.turn === "w") {
    indColor.className =
      "w-4 h-4 rounded-full bg-white border-2 border-slate-300 shadow-[0_0_10px_rgba(255,255,255,0.2)]";
    if (game.gameMode === "online") {
      indText.innerHTML =
        myColor === "w"
          ? "Putih (Anda)"
          : 'Putih <span class="animate-pulse text-cyan-400 text-[10px] ml-1 uppercase tracking-wider font-bold">(Menunggu lawan...)</span>';
    } else {
      indText.innerText = "Putih (Anda)";
    }
  } else {
    indColor.className =
      "w-4 h-4 rounded-full bg-slate-800 border-2 border-slate-600 shadow-inner";
    if (game.gameMode === "ai") {
      indText.innerHTML =
        'Hitam (AI) <span class="animate-pulse text-indigo-400 text-[10px] ml-1 uppercase tracking-wider font-bold">(Sedang berfikir...)</span>';
    } else if (game.gameMode === "online") {
      indText.innerHTML =
        myColor === "b"
          ? "Hitam (Anda)"
          : 'Hitam <span class="animate-pulse text-cyan-400 text-[10px] ml-1 uppercase tracking-wider font-bold">(Menunggu lawan...)</span>';
    } else {
      indText.innerText = "Hitam";
    }
  }
  document
    .getElementById("check-alert-white")
    .classList.toggle("hidden", !game.isKingInCheck("w"));
  document
    .getElementById("check-alert-black")
    .classList.toggle("hidden", !game.isKingInCheck("b"));
  if (
    result &&
    (result.status === "checkmate" ||
      result.status === "stalemate" ||
      result.status === "resign" ||
      result.status === "disconnect" ||
      result.status === "gameover" ||
      result.status === "draw")
  ) {
    game.isGameOver = true;
    let overMessage = "Permainan Seri (Stalemate)!";
    if (result.status === "checkmate")
      overMessage = `Pemain ${result.winner === "w" ? "Putih" : "Hitam"} menang (Checkmate)!`;
    else if (result.status === "resign")
      overMessage = `Pemain ${result.winner === "w" ? "Putih" : "Hitam"} menang (Lawan Mengalah)!`;
    else if (result.status === "disconnect")
      overMessage = `Pemain ${result.winner === "w" ? "Putih" : "Hitam"} menang (Lawan Terputus)!`;
    else if (result.status === "gameover")
      overMessage =
        result.reason === "stalemate"
          ? "Permainan Seri (Stalemate)!"
          : `Pemain ${result.winner === "w" ? "Putih" : "Hitam"} menang (Checkmate)!`;
    else if (result.status === "draw")
      overMessage = "Permainan Seri (Kesepakatan)!";
    else if (result.reason) overMessage = `Permainan Seri (${result.reason})!`;
    document.getElementById("game-over-result").innerText = overMessage;
    const overlay = document.getElementById("game-over-overlay");
    overlay.classList.remove("hidden");
    setTimeout(() => overlay.classList.remove("opacity-0"), 50);
    const badge = document.getElementById("game-status-badge");
    badge.className =
      "px-3 py-1 text-[10px] font-bold rounded-full bg-rose-500/10 text-rose-400 border border-rose-500/20 uppercase tracking-widest";
    badge.innerText = "Tamat";
    // Permainan berakhir — tutup modal disconnect jika masih terbuka.
    if (opponentOfflineModalOpen) {
      opponentOfflineModalOpen = false;
      window.Swal.close();
    }
    // Aksi pasca-game: multiplayer → tanding ulang / keluar; lokal/AI → main semula.
    const restartOverlayBtn = document.getElementById("btn-restart-overlay");
    const rematchActions = document.getElementById("rematch-actions");
    if (restartOverlayBtn)
      restartOverlayBtn.classList.toggle("hidden", game.gameMode === "online");
    if (rematchActions)
      rematchActions.classList.toggle("hidden", game.gameMode !== "online");
    updateRematchButton();
  }
  updateActionButtons();
}
// ── RESIGN & DRAW (multiplayer) ──────────────────────────────────────────
// Aturan FIDE: resign sepihak kapan saja (5.1.2); draw butuh kesepakatan (9.1)
// — pemain yang gilirannya menawarkan, lawan menerima atau menolak.
function updateActionButtons() {
  const offerBtn = document.getElementById("btn-offer-draw");
  const resignBtn = document.getElementById("btn-resign");
  if (!offerBtn || !resignBtn) return;
  const online = game.gameMode === "online";
  // Belum dimulai: overlay pilih warna / menunggu lawan masih menutupi papan.
  const waiting =
    online &&
    colorPickerOverlay &&
    !colorPickerOverlay.classList.contains("hidden");
  const myTurn = online && !!myColor && game.turn === myColor && !game.isGameOver;
  // Tombol seri/menyerah hanya muncul setelah lawan bergabung DAN sudah ada
  // minimal satu langkah (permainan benar-benar dimulai). Saat baru masuk mode
  // multiplayer / masih menunggu lawan / belum ada langkah, tombol disembunyikan.
  const showActions = online && opponentJoined && game.history.length > 0;
  // Tombol "Tawarkan Seri" HANYA tampil saat giliran pemain (FIDE 9.1.2) —
  // kalau bukan gilirannya, disembunyikan total agar tidak membingungkan
  // (tombol terlihat tapi tidak bisa diklik). Saat tawaran seri saya masih
  // pending, tombol tetap tampil sebagai "Menunggu jawaban..." (disabled).
  const offerVisible = showActions && myTurn && !waiting;
  offerBtn.classList.toggle("hidden", !offerVisible);
  offerBtn.disabled = !myTurn || drawOfferPending || drawModalShown || waiting;
  offerBtn.innerText = drawOfferPending ? "Menunggu jawaban..." : "Tawarkan Seri";
  resignBtn.classList.toggle("hidden", !showActions);
  // Saat tombol seri disembunyikan, "Mengalah" melebar penuh (2 kolom) agar
  // grid tidak meninggalkan kolom kosong.
  resignBtn.classList.toggle("col-span-2", showActions && !offerVisible);
  resignBtn.disabled = !online || !roomCode || game.isGameOver || waiting;
}
// Proses event non-langkah dari polling / sync. silent=true dipakai saat sync
// (jangan munculkan modal). Mengembalikan result terminal atau null.
function handleGameEvent(payload, silent = false) {
  const type = payload.type;
  const color = payload.color;
  const isSelf = color === myColor;
  // Untuk event terminal (resign/disconnect/draw_accept): saat sync (silent)
  // event sendiri TETAP diproses — pemain yang kembali setelah disconnect
  // perlu melihat papan sudah berakhir. Saat polling langsung (non-silent),
  // echo aksi sendiri dilewati karena sudah diproses lokal.
  if (type === "resign") {
    if (!silent && isSelf) return null; // echo aksi sendiri — sudah diproses lokal
    return { status: "resign", winner: color === "w" ? "b" : "w" };
  }
  if (type === "disconnect") {
    if (!silent && isSelf) return null;
    return { status: "disconnect", winner: color === "w" ? "b" : "w" };
  }
  if (type === "game_over") {
    // Event checkmate/stalemate — color = pecundang (konsisten dgn resign).
    if (!silent && isSelf) return null;
    return {
      status: "gameover",
      winner: color === "w" ? "b" : "w",
      reason: payload.reason || null,
    };
  }
  if (type === "draw_offer") {
    if (isSelf) return null; // echo sendiri
    if (!silent && !drawModalShown && !game.isGameOver) showDrawOfferModal();
    return null;
  }
  if (type === "draw_accept") {
    if (!silent && isSelf) return null; // echo sendiri — sudah diproses lokal
    return { status: "draw", reason: "Agreement" };
  }
  if (type === "draw_decline") {
    if (!isSelf) {
      drawOfferPending = false; // lawan menolak tawaran saya
      updateActionButtons();
    }
    return null;
  }
  if (type === "rematch_offer") {
    if (isSelf) return null; // echo sendiri
    if (!silent && !rematchModalShown && game.isGameOver) showRematchModal();
    return null;
  }
  if (type === "rematch_accept") {
    if (!silent && isSelf) return null; // echo sendiri — sudah di-reset lokal
    startRematch(); // lawan menerima → mulai game baru di room yang sama
    return null;
  }
  if (type === "rematch_decline") {
    if (isSelf) return null; // echo sendiri
    // Lawan menolak / membatalkan → beri tahu + otomatis keluar ke mode lokal.
    showRematchRejectedThenExit();
    return null;
  }
  return null;
}
function showDrawOfferModal() {
  if (drawModalShown || game.isGameOver) return;
  drawModalShown = true;
  updateActionButtons();
  window.Swal.fire({
    title: "Tawaran Seri",
    html: "Lawan menawarkan hasil seri.<br>Adakah anda mahu menerimanya?",
    icon: "question",
    background: "#0f172a",
    color: "#fff",
    showCancelButton: true,
    confirmButtonText: "TERIMA",
    cancelButtonText: "TOLAK",
    reverseButtons: true,
    allowOutsideClick: false,
    allowEscapeKey: false,
  }).then((result) => {
    drawModalShown = false;
    updateActionButtons();
    if (result.isConfirmed) {
      sendGameActionAPI(roomCode, "draw_accept").then((d) => {
        if (d.success) {
          game.isGameOver = true;
          updateGameStatus({ status: "draw", reason: "Agreement" });
        } else {
          window.meelAlert({
            title: "Gagal",
            text: d.message || "Gagal menerima tawaran seri.",
            icon: "error",
          });
        }
      });
    } else if (result.dismiss === window.Swal.DismissReason.cancel) {
      sendGameActionAPI(roomCode, "draw_decline").then(() => {});
    }
  });
}
function handleOfferDraw() {
  if (game.gameMode !== "online" || !roomCode || game.isGameOver) return;
  if (
    colorPickerOverlay &&
    !colorPickerOverlay.classList.contains("hidden")
  )
    return; // masih menunggu lawan — belum mulai
  if (game.turn !== myColor) {
    window.meelAlert({
      title: "Bukan Giliran",
      text: "Anda hanya boleh menawarkan seri ketika giliran anda.",
      icon: "warning",
    });
    return;
  }
  sendGameActionAPI(roomCode, "draw_offer").then((d) => {
    if (!d.success) {
      window.meelAlert({
        title: "Gagal",
        text: d.message || "Gagal menawarkan seri.",
        icon: "error",
      });
      return;
    }
    drawOfferPending = true;
    updateActionButtons();
  });
}
function handleResign() {
  if (game.gameMode !== "online" || !roomCode || game.isGameOver) return;
  if (
    colorPickerOverlay &&
    !colorPickerOverlay.classList.contains("hidden")
  )
    return; // masih menunggu lawan — belum mulai
  window
    .meelConfirm({
      title: "Mengalah?",
      text: "Adakah anda pasti mahu mengalah? Lawan akan dinyatakan menang.",
      confirmButtonText: "MENGALAH",
    })
    .then((isConfirmed) => {
      if (!isConfirmed) return;
      sendGameActionAPI(roomCode, "resign").then((d) => {
        if (!d.success) {
          window.meelAlert({
            title: "Gagal",
            text: d.message || "Gagal mengalah.",
            icon: "error",
          });
          return;
        }
        game.isGameOver = true;
        updateGameStatus({ status: "resign", winner: myColor === "w" ? "b" : "w" });
      });
    });
}
// ── REMATCH (TANDING ULANG) — multiplayer pasca-game ────────────────────────
// Game selesai → overlay menawarkan "Tanding Lagi?" / "Keluar".
//  - Tanding Lagi? → kirim rematch_offer → lawan dapat modal Terima/Tolak.
//      TERIMA → rematch_accept (server reset riwayat) → kedua papan di-reset.
//      TOLAK  → rematch_decline → penawar lihat "Permainan telah keluar" dan
//               otomatis kembali ke mode "Lawan Rakan" dalam 5 detik.
//  - Keluar → batalkan tawaran bila pending, lalu langsung ke mode lokal.
function updateRematchButton() {
  const btn = document.getElementById("btn-rematch");
  const exitBtn = document.getElementById("btn-exit-game");
  if (btn) {
    btn.disabled = rematchOfferPending || rematchModalShown || rematchLeaving;
    btn.innerText = rematchOfferPending ? "Menunggu jawaban..." : "Tanding Lagi?";
  }
  if (exitBtn) exitBtn.disabled = rematchLeaving;
}
function startRematch() {
  rematchOfferPending = false;
  rematchModalShown = false;
  restartGame(); // reset papan + sembunyikan overlay + status "Online"
}
function showRematchModal() {
  if (rematchModalShown || !game.isGameOver) return;
  rematchModalShown = true;
  updateRematchButton();
  window.Swal.fire({
    title: "Tawaran Tanding Ulang",
    html: "Lawan mengajukan tanding ulang.<br>Adakah anda mahu menerimanya?",
    icon: "question",
    background: "#0f172a",
    color: "#fff",
    showCancelButton: true,
    confirmButtonText: "TERIMA",
    cancelButtonText: "TOLAK",
    reverseButtons: true,
    allowOutsideClick: false,
    allowEscapeKey: false,
  }).then((result) => {
    rematchModalShown = false;
    updateRematchButton();
    if (result.isConfirmed) {
      sendGameActionAPI(roomCode, "rematch_accept")
        .then((d) => {
          if (d && d.success) {
            startRematch();
            return;
          }
          if (d && d.message === "Tidak ada tawaran tanding ulang yang menunggu.") {
            // Lawan sudah menolak/membatalkan saat kita menekan TERIMA.
            showRematchRejectedThenExit();
            return;
          }
          // Gagal lain (jaringan/server) — tawaran masih pending, buka lagi
          // modal agar bisa mencoba sekali lagi (hindari deadlock accept).
          showRematchModal();
        })
        .catch(() => {
          showRematchModal(); // gagal jaringan — tawaran masih pending
        });
    } else if (result.dismiss === window.Swal.DismissReason.cancel) {
      sendGameActionAPI(roomCode, "rematch_decline").then(() => {});
      exitToLocalMode();
    }
  });
}
function handleRematch() {
  if (game.gameMode !== "online" || !roomCode || !game.isGameOver) return;
  if (rematchOfferPending || rematchModalShown || rematchLeaving) return;
  sendGameActionAPI(roomCode, "rematch_offer").then((d) => {
    if (!d.success) {
      window.meelAlert({
        title: "Gagal",
        text: d.message || "Gagal menawarkan tanding ulang.",
        icon: "error",
      });
      return;
    }
    rematchOfferPending = true;
    updateRematchButton();
  });
}
function handleExitGame() {
  if (game.gameMode !== "online" || rematchLeaving) return;
  rematchLeaving = true;
  updateRematchButton();
  // Sedang menunggu jawaban tawaran? Batalkan agar lawan tidak menunggu.
  if (rematchOfferPending && roomCode) {
    sendGameActionAPI(roomCode, "rematch_decline").catch(() => {});
  }
  exitToLocalMode();
}
function showRematchRejectedThenExit() {
  rematchOfferPending = false;
  rematchModalShown = false;
  rematchLeaving = true; // kunci tombol overlay selama countdown keluar
  updateRematchButton();
  if (window.Swal) window.Swal.close(); // tutup modal tawaran bila terbuka
  window.Swal.fire({
    title: "Tamat",
    html: "Permainan telah keluar.<br>Kembali ke mode <b>Lawan Rakan</b> dalam 5 saat...",
    icon: "info",
    background: "#0f172a",
    color: "#fff",
    timer: 5000,
    timerProgressBar: true,
    showConfirmButton: false,
    allowOutsideClick: false,
    allowEscapeKey: false,
  });
  setTimeout(() => {
    if (game.gameMode === "online") exitToLocalMode();
  }, 5000);
}
function exitToLocalMode() {
  if (typeof exitToLocalModeFn === "function") exitToLocalModeFn();
}
// ── DETEKSI LAWAN TERPUTUS (disconnect) ────────────────────────────────────
// Server mengirim opponent_online=false (dari get_move.php) hanya jika
// users.last_activity lawan sudah > CHESS_OPPONENT_OFFLINE_SECONDS (90 dtk).
// Klaim kemenangan tetap diverifikasi ulang di server (game_action.php),
// jadi client tidak bisa mengklaim palsu hanya dengan manipulasi UI.
function handleOpponentStatus(online) {
  if (game.gameMode !== "online" || !roomCode || game.isGameOver) return;
  if (online === opponentOnline) return;
  if (online) {
    // Lawan kembali terhubung — tutup modal & reset notifikasi.
    opponentOnline = true;
    opponentOfflineNotified = false;
    opponentOfflineNextPromptAt = 0;
    if (opponentOfflineModalOpen) {
      opponentOfflineModalOpen = false;
      window.Swal.close();
    }
    window.meelAlert({
      title: "Lawan Kembali",
      text: "Lawan telah terhubung kembali. Permainan berlanjut.",
      icon: "success",
    });
  } else {
    opponentOnline = false;
    if (!opponentOfflineNotified || Date.now() >= opponentOfflineNextPromptAt) {
      opponentOfflineNotified = true;
      opponentOfflineNextPromptAt = 0;
      showOpponentOfflineModal();
    }
  }
}
function showOpponentOfflineModal() {
  if (opponentOfflineModalOpen || game.isGameOver) return;
  opponentOfflineModalOpen = true;
  window.Swal.fire({
    title: "Lawan Terputus",
    html: "Lawan kehilangan sambungan.<br>Anda boleh <b>mengklaim kemenangan</b> atau menunggu lawan kembali.",
    icon: "warning",
    background: "#0f172a",
    color: "#fff",
    showCancelButton: true,
    confirmButtonText: "KLAIM KEMENANGAN",
    cancelButtonText: "TUNGGU",
    reverseButtons: true,
    allowOutsideClick: false,
    allowEscapeKey: false,
  }).then((result) => {
    opponentOfflineModalOpen = false;
    if (result.isConfirmed) {
      claimDisconnectWin();
    } else if (result.dismiss === window.Swal.DismissReason.cancel) {
      // Tunggu dulu — prompt ulang 60 detik lagi jika masih offline.
      opponentOfflineNotified = true;
      opponentOfflineNextPromptAt = Date.now() + 60000;
    }
  });
}
function claimDisconnectWin() {
  if (!roomCode || game.isGameOver) return;
  sendGameActionAPI(roomCode, "disconnect_win").then((d) => {
    if (!d.success) {
      window.meelAlert({
        title: "Tidak Dapat Mengklaim",
        text: d.message || "Gagal mengklaim kemenangan.",
        icon: "error",
      });
      // Lawan mungkin sudah kembali — beri kesempatan prompt ulang.
      opponentOfflineNotified = false;
      opponentOfflineNextPromptAt = Date.now() + 30000;
      return;
    }
    game.isGameOver = true;
    opponentOnline = true;
    opponentOfflineNotified = false;
    updateGameStatus({ status: "disconnect", winner: myColor });
  });
}
// Kirim event game_over ke server saat game online berakhir checkmate/stalemate
// (langkah terminal baru dieksekusi). Server mencatatnya agar GC tidak
// menganggap game ini "macet di tengah". Duplikat aman — game_action.php
// menolak bila game sudah berakhir; flag gameOverEventSent mencegah spam.
function notifyGameOver(result) {
  if (game.gameMode !== "online" || !roomCode) return;
  if (!result || (result.status !== "checkmate" && result.status !== "stalemate"))
    return;
  if (gameOverEventSent) return;
  gameOverEventSent = true;
  // Pecundang = sisi yang harus melangkah setelah langkah terminal
  // (game.turn sudah di-switch oleh executeMove). Berlaku untuk checkmate
  // maupun stalemate — dan benar walau engine tidak mengisi winner saat
  // stalemate. Konsisten dengan validasi server (lawan dari langkah terakhir).
  const loser = game.turn;
  sendGameActionAPI(roomCode, "game_over", {
    color: loser,
    reason: result.status === "checkmate" ? "checkmate" : "stalemate",
  })
    .then((d) => {
      // Tolakan "Permainan sudah berakhir" = duplikat — biarkan flag terset.
      if (d && !d.success && d.message !== "Permainan sudah berakhir.") {
        gameOverEventSent = false; // gagal lain — izinkan coba lagi
      }
    })
    .catch(() => {
      gameOverEventSent = false; // gagal jaringan — coba lagi
    });
}
function restartGame() {
  game.reset();
  localBoardFlipped = false;
  drawOfferPending = false;
  drawModalShown = false;
  rematchOfferPending = false;
  rematchModalShown = false;
  rematchLeaving = false;
  opponentOnline = true;
  opponentOfflineNotified = false;
  opponentOfflineNextPromptAt = 0;
  opponentOfflineModalOpen = false;
  gameOverEventSent = false;
  const overlay = document.getElementById("game-over-overlay");
  overlay.classList.add("opacity-0");
  setTimeout(() => overlay.classList.add("hidden"), 300);
  const badge = document.getElementById("game-status-badge");
  badge.className =
    game.gameMode === "online"
      ? "px-3 py-1 text-[10px] font-bold rounded-full bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 uppercase tracking-widest shadow-[0_0_10px_rgba(34,211,238,0.1)]"
      : "px-3 py-1 text-[10px] font-bold rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 uppercase tracking-widest shadow-[0_0_10px_rgba(16,185,129,0.1)]";
  badge.innerText = game.gameMode === "online" ? "Online" : "Aktif";
  const btnRestart = document.getElementById("btn-restart");
  if (btnRestart) {
    if (game.gameMode === "online") {
      btnRestart.classList.add("hidden");
    } else {
      btnRestart.classList.remove("hidden");
    }
  }
  renderBoard();
  updateGameStatus(null);
}
// SETUP DOM EVENTS
document.addEventListener("DOMContentLoaded", () => {
  const onlineBtn = document.getElementById("mode-vs-online");
  const btnLocal = document.getElementById("mode-vs-local");
  const btnAi = document.getElementById("mode-vs-ai");
  const diffCont = document.getElementById("ai-difficulty-container");
  const panel = document.getElementById("multiplayer-panel");
  // ── Alert "Game Sedang Berjalan" ─────────────────────────────────────────
  // Semua tombol mode me-reset papan (restartGame). Jika masih ada langkah
  // yang sudah dimainkan, minta konfirmasi dulu sebelum berpindah mode.
  function confirmSwitchMode(proceed) {
    if (game.history.length > 0 && !game.isGameOver) {
      window.Swal.fire({
        title: "Game Sedang Berjalan",
        html: "Masih ada langkah yang sudah dimainkan.<br>Berpindah mode akan <b>menghapus permainan saat ini</b>.",
        icon: "warning",
        background: "#0f172a",
        color: "#fff",
        showCancelButton: true,
        confirmButtonText: "LANJUT",
        cancelButtonText: "BATAL",
        reverseButtons: true,
      }).then((result) => {
        if (result.isConfirmed) proceed();
      });
    } else {
      proceed();
    }
  }

  if (onlineBtn) {
    // Masuk mode multiplayer: tampilkan panel room + reset papan.
    function enterOnlineMode() {
      // Reset state multiplayer — user memilih warna lewat overlay papan.
      if (roomStatusTimer) {
        clearInterval(roomStatusTimer);
        roomStatusTimer = null;
      }
      stopPolling();
      roomCode = null;
      myColor = null;
      lastMoveId = 0;
      suppressNetworkSync = false;
      opponentJoined = false;
      game.gameMode = "online";
      resetAllModes();
      if (panel) panel.classList.remove("hidden");
      if (diffCont) diffCont.classList.add("hidden");
      onlineBtn.className =
        "flex items-center justify-between p-3.5 rounded-xl border border-cyan-500/40 bg-cyan-950/30 text-cyan-300 shadow-inner text-left transition-all duration-200 active:scale-[0.98] w-full group";
      const onlineIndicator = onlineBtn.querySelector(".mode-indicator");
      if (onlineIndicator) {
        onlineIndicator.innerHTML =
          '<div class="w-1.5 h-1.5 rounded-full bg-white"></div>';
        onlineIndicator.className =
          "mode-indicator w-3.5 h-3.5 rounded-full bg-cyan-500 shadow-[0_0_8px_rgba(34,211,238,0.6)] flex items-center justify-center";
      }
      if (blackName) blackName.innerText = "Pemain Hitam";
      updateRoomUI();
      restartGame();
      showColorPicker(); // timpa papan dengan pilihan warna
    }
    onlineBtn.addEventListener("click", () => {
      // Sudah di mode multiplayer — jangan tanya lagi.
      if (game.gameMode === "online") return;
      confirmSwitchMode(() => {
        // Alert konfirmasi sebelum masuk mode multiplayer.
        window.Swal.fire({
          title: "Mode Multiplayer",
          html: 'Bermain dengan pemain lain memerlukan <b>akun login</b>.<br>Anda akan membuat atau bergabung room menggunakan <b>Room Code</b> yang dibagikan.',
          icon: "info",
          background: "#0f172a",
          color: "#fff",
          showCancelButton: true,
          confirmButtonText: "LANJUT",
          cancelButtonText: "BATAL",
          reverseButtons: true,
        }).then((result) => {
          if (result.isConfirmed) enterOnlineMode();
        });
      });
    });
  }
  // Highlight tombol mode "Lawan Rakan" + reset tombol lain.
  // Dipakai oleh enterLocalMode() dan leaveRoom() supaya highlight tombol
  // mode selalu sinkron dengan mode yang sedang aktif.
  function highlightLocalModeButton() {
    resetAllModes();
    if (!btnLocal) return;
    btnLocal.className =
      "flex items-center justify-between p-3.5 rounded-xl border border-emerald-500/40 bg-emerald-950/30 text-emerald-300 shadow-inner text-left transition-all duration-200 active:scale-[0.98] w-full group";
    const localIndicator = btnLocal.querySelector(".mode-indicator");
    if (localIndicator) {
      localIndicator.innerHTML =
        '<div class="w-1.5 h-1.5 rounded-full bg-white"></div>';
      localIndicator.className =
        "mode-indicator w-3.5 h-3.5 rounded-full bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.6)] flex items-center justify-center";
    }
  }
  if (btnLocal) {
    function enterLocalMode() {
      // Keluar dari session online (room, polling, overlay) kalau ada.
      if (game.gameMode === "online") stopOnlineSession();
      game.gameMode = "local";
      if (diffCont) diffCont.classList.add("hidden");
      highlightLocalModeButton();
      if (blackName) blackName.innerText = "Pemain Hitam";
      updateRoomUI();
      restartGame();
    }
    btnLocal.addEventListener("click", () => {
      if (game.gameMode === "local") return;
      confirmSwitchMode(enterLocalMode);
    });
  }
  if (btnAi) {
    function enterAiMode() {
      // Keluar dari session online (room, polling, overlay) kalau ada.
      if (game.gameMode === "online") stopOnlineSession();
      game.gameMode = "ai";
      resetAllModes();
      if (diffCont) diffCont.classList.remove("hidden");
      btnAi.className =
        "flex items-center justify-between p-3.5 rounded-xl border border-indigo-500/40 bg-indigo-950/30 text-indigo-300 shadow-inner text-left transition-all duration-200 active:scale-[0.98] w-full group";
      const aiIndicator = btnAi.querySelector(".mode-indicator");
      if (aiIndicator) {
        aiIndicator.innerHTML =
          '<div class="w-1.5 h-1.5 rounded-full bg-white"></div>';
        aiIndicator.className =
          "mode-indicator w-3.5 h-3.5 rounded-full bg-indigo-500 shadow-[0_0_8px_rgba(99,102,241,0.6)] flex items-center justify-center";
      }
      if (blackName) blackName.innerText = "Komputer (AI)";
      updateRoomUI();
      restartGame();
    }
    btnAi.addEventListener("click", () => {
      if (game.gameMode === "ai") return;
      confirmSwitchMode(enterAiMode);
    });
  }
  // ── Multiplayer: alur dimulai dari color picker di atas papan ──
  async function createRoom() {
    if (roomStatusTimer) {
      clearInterval(roomStatusTimer);
      roomStatusTimer = null;
    }
    stopPolling();
    const data = await createRoomAPI();
    if (!data.success) {
      window.meelAlert({
        title: "Gagal",
        text: data.message || "Gagal buat room.",
        icon: "error",
      });
      return;
    }
    roomCode = data.room;
    myColor = "w";
    lastMoveId = 0;
    restartGame();
    updateRoomUI();
    document.getElementById("room-code-display").innerText = roomCode;
    document.getElementById("room-color").innerText = "Putih";
    document.getElementById("room-status").innerText =
      "Room dibuat. Bagi code ini ke rakan.";
    showWaitingState(roomCode); // overlay: room code + menunggu lawan
    tungguLawanBergabung(roomCode);
  }

  async function joinRoom() {
    const { value: code } = await window.Swal.fire({
      title: "Masukkan Room Code",
      input: "text",
      inputPlaceholder: "Room Code",
      showCancelButton: true,
      confirmButtonText: "GABUNG",
      cancelButtonText: "BATAL",
      background: "#0f172a",
      color: "#fff",
      reverseButtons: true,
      inputValidator: (v) => (v ? null : "Room code wajib diisi!"),
    });
    if (!code) return;
    const data = await joinRoomAPI(code);
    if (!data.success) {
      window.meelAlert({
        title: "Gagal",
        text: data.message || "Room tidak wujud atau penuh.",
        icon: "error",
      });
      return;
    }
    roomCode = data.room;
    myColor = "b";
    opponentJoined = true; // sudah masuk room — lawan (putih) sudah ada
    lastMoveId = 0;
    document.getElementById("room-code-display").innerText = roomCode;
    document.getElementById("room-color").innerText = "Hitam";
    document.getElementById("room-status").innerText =
      "Sudah masuk room. Sync papan...";
    restartGame();
    // finally: pastikan overlay selalu tertutup & polling jalan walau sync gagal,
    // supaya pemain Hitam tidak terjebak di balik overlay.
    try {
      await syncRoomState(true);
    } finally {
      hideColorPicker(); // game dimulai — papan aktif & bisa diklik
      startPolling();
    }
    updateRoomUI();
  }

  // Bersihkan session online (polling, room, overlay) tanpa mengganti mode.
  function stopOnlineSession() {
    if (roomStatusTimer) {
      clearInterval(roomStatusTimer);
      roomStatusTimer = null;
    }
    stopPolling();
    roomCode = null;
    myColor = null;
    lastMoveId = 0;
    suppressNetworkSync = false;
    opponentJoined = false;
    hideColorPicker();
    const panelEl = document.getElementById("multiplayer-panel");
    if (panelEl) panelEl.classList.add("hidden");
  }

  // Keluar penuh dari multiplayer → kembali ke mode lokal.
  function leaveRoom() {
    stopOnlineSession();
    document.getElementById("room-code-display").innerText = "-";
    document.getElementById("room-color").innerText = "-";
    document.getElementById("room-status").innerText = "Belum masuk room.";
    if (blackName) blackName.innerText = "Pemain Hitam";
    game.gameMode = "local";
    if (diffCont) diffCont.classList.add("hidden");
    // Reset highlight tombol mode: multiplayer tidak lagi aktif, highlight
    // harus pindah ke "Lawan Rakan" (bug: tombol multiplayer tetap menyala).
    highlightLocalModeButton();
    restartGame();
    updateRoomUI();
  }

  // Ekspos leaveRoom() ke fungsi top-level (rematch / handleGameEvent).
  exitToLocalModeFn = leaveRoom;

  // Batal saat menunggu lawan → kembali ke pilihan warna (tetap di multiplayer).
  function cancelWaiting() {
    if (roomStatusTimer) {
      clearInterval(roomStatusTimer);
      roomStatusTimer = null;
    }
    stopPolling();
    roomCode = null;
    myColor = null;
    lastMoveId = 0;
    drawOfferPending = false;
    drawModalShown = false;
    rematchOfferPending = false;
    rematchModalShown = false;
    rematchLeaving = false;
    opponentJoined = false;
    document.getElementById("room-code-display").innerText = "-";
    document.getElementById("room-color").innerText = "-";
    document.getElementById("room-status").innerText = "Belum masuk room.";
    updateRoomUI();
    showColorPicker();
  }

  if (btnPickWhite) btnPickWhite.addEventListener("click", createRoom);
  if (btnPickBlack) btnPickBlack.addEventListener("click", joinRoom);
  if (btnCancelWaiting) btnCancelWaiting.addEventListener("click", cancelWaiting);
  document
    .getElementById("btn-leave-room")
    .addEventListener("click", leaveRoom);
  const btnOfferDraw = document.getElementById("btn-offer-draw");
  const btnResign = document.getElementById("btn-resign");
  if (btnOfferDraw) btnOfferDraw.addEventListener("click", handleOfferDraw);
  if (btnResign) btnResign.addEventListener("click", handleResign);
  const btnRematch = document.getElementById("btn-rematch");
  const btnExitGame = document.getElementById("btn-exit-game");
  if (btnRematch) btnRematch.addEventListener("click", handleRematch);
  if (btnExitGame) btnExitGame.addEventListener("click", handleExitGame);
  document.getElementById("btn-restart").addEventListener("click", () => {
    // Fitur: Tampilkan alert konfirmasi jika permainan sedang berjalan
    if (game.history.length > 0 && !game.isGameOver) {
      window
        .meelConfirm({
          title: "Mula Semula?",
          text: "Adakah anda pasti mahu mula semula? Kemajuan permainan saat ini akan dipadam.",
          confirmButtonText: "MULA SEMULA",
        })
        .then((isConfirmed) => {
          if (isConfirmed) {
            restartGame();
          }
        });
    } else {
      restartGame();
    }
  });
  document
    .getElementById("btn-restart-overlay")
    .addEventListener("click", restartGame);
  document.querySelectorAll("[data-level]").forEach((btn) => {
    btn.addEventListener("click", (e) => {
      game.aiDifficulty = e.target.dataset.level;
      document
        .querySelectorAll("[data-level]")
        .forEach(
          (b) =>
            (b.className =
              "py-2 text-xs font-bold rounded-lg border border-slate-700 hover:border-slate-500 text-slate-400 hover:text-slate-200 transition-all"),
        );
      e.target.className =
        "py-2 text-xs font-bold rounded-lg border border-indigo-500/40 bg-indigo-950/30 text-indigo-300 transition-all shadow-inner";
    });
  });
  document.body.addEventListener(
    "click",
    () => {
      if (!sounds.initialized) sounds.init();
    },
    { once: true },
  );
  // Inisialisasi ikon Lucide
  if (window.lucide) {
    window.lucide.createIcons();
  }
  renderBoard();
});

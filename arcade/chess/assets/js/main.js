import { SVG_PIECES, UNICODE_PIECES } from "./assets.js";
import { sounds } from "./audio.js";
import { ChessGame } from "./engine.js";
import {
  saveMoveAPI,
  fetchMovesAPI,
  checkRoomStatusAPI,
  createRoomAPI,
  joinRoomAPI,
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
// DOM Elements
const boardEl = document.getElementById("chess-board");
const moveHistoryList = document.getElementById("move-history-list");
const promotionModal = document.getElementById("promotion-modal");
const blackName = document.getElementById("player-black-name");
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
async function syncRoomState(resetBoard = false) {
  if (!roomCode) return;
  const moves = await fetchMovesAPI(roomCode, 0);
  suppressNetworkSync = true;
  game.muteSounds = true;
  if (resetBoard) game.reset();
  for (const move of moves) {
    lastMoveId = move.id;
    const payload =
      typeof move.move_data === "string"
        ? JSON.parse(move.move_data)
        : move.move_data;
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
  updateGameStatus(null);
}
function startPolling() {
  stopPolling();
  if (!roomCode) return;
  pollingTimer = setInterval(async () => {
    try {
      const moves = await fetchMovesAPI(roomCode, lastMoveId);
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
        if (payload.color === myColor) continue;
        const result = game.executeMove(
          payload.fromR,
          payload.fromC,
          payload.toR,
          payload.toC,
          payload.promotedPieceType || null,
        );
        if (result && result !== "promotion") lastResult = result;
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
        document.getElementById("room-status").innerText =
          "Lawan bergabung! Menunggu langkah...";
        document.getElementById("player-black-name").innerText =
          "Pemain Hitam (Online)";
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
          },
        );
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
            },
          );
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
        `<span class="text-slate-300 drop-shadow">${UNICODE_PIECES[p] || p}</span>`,
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
    (result.status === "checkmate" || result.status === "stalemate")
  ) {
    let overMessage = "Permainan Seri (Stalemate)!";
    if (result.status === "checkmate")
      overMessage = `Pemain ${result.winner === "w" ? "Putih" : "Hitam"} menang (Checkmate)!`;
    else if (result.reason) overMessage = `Permainan Seri (${result.reason})!`;
    document.getElementById("game-over-result").innerText = overMessage;
    const overlay = document.getElementById("game-over-overlay");
    overlay.classList.remove("hidden");
    setTimeout(() => overlay.classList.remove("opacity-0"), 50);
    const badge = document.getElementById("game-status-badge");
    badge.className =
      "px-3 py-1 text-[10px] font-bold rounded-full bg-rose-500/10 text-rose-400 border border-rose-500/20 uppercase tracking-widest";
    badge.innerText = "Tamat";
  }
}
function restartGame() {
  game.reset();
  localBoardFlipped = false;
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
  if (onlineBtn) {
    onlineBtn.addEventListener("click", () => {
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
      if (blackName)
        blackName.innerText =
          myColor === "b" ? "Pemain Hitam (Anda)" : "Pemain Hitam";
      updateRoomUI();
      restartGame();
    });
  }
  if (btnLocal) {
    btnLocal.addEventListener("click", () => {
      game.gameMode = "local";
      resetAllModes();
      if (diffCont) diffCont.classList.add("hidden");
      btnLocal.className =
        "flex items-center justify-between p-3.5 rounded-xl border border-emerald-500/40 bg-emerald-950/30 text-emerald-300 shadow-inner text-left transition-all duration-200 active:scale-[0.98] w-full group";
      const localIndicator = btnLocal.querySelector(".mode-indicator");
      if (localIndicator) {
        localIndicator.innerHTML =
          '<div class="w-1.5 h-1.5 rounded-full bg-white"></div>';
        localIndicator.className =
          "mode-indicator w-3.5 h-3.5 rounded-full bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.6)] flex items-center justify-center";
      }
      if (blackName) blackName.innerText = "Pemain Hitam";
      updateRoomUI();
      restartGame();
    });
  }
  if (btnAi) {
    btnAi.addEventListener("click", () => {
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
    });
  }
  document
    .getElementById("btn-create-room")
    .addEventListener("click", async () => {
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
      game.gameMode = "online";
      restartGame();
      updateRoomUI();
      document.getElementById("room-code-display").innerText = roomCode;
      document.getElementById("room-color").innerText = "Putih";
      document.getElementById("room-status").innerText =
        "Room dibuat. Bagi code ini ke rakan.";
      tungguLawanBergabung(roomCode);
    });

  document
    .getElementById("btn-join-room")
    .addEventListener("click", async () => {
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
      lastMoveId = 0;
      document.getElementById("room-code-display").innerText = roomCode;
      document.getElementById("room-color").innerText = "Hitam";
      document.getElementById("room-status").innerText =
        "Sudah masuk room. Sync papan...";
      game.gameMode = "online";
      restartGame();
      await syncRoomState(true);
      startPolling();
      updateRoomUI();
    });
  document.getElementById("btn-leave-room").addEventListener("click", () => {
    if (roomStatusTimer) {
      clearInterval(roomStatusTimer);
      roomStatusTimer = null;
    }
    stopPolling();
    roomCode = null;
    myColor = null;
    lastMoveId = 0;
    document.getElementById("room-code-display").innerText = "-";
    document.getElementById("room-color").innerText = "-";
    document.getElementById("room-status").innerText = "Belum masuk room.";
    game.gameMode = "local";
    restartGame();
    updateRoomUI();
  });
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

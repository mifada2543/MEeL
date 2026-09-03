import { sounds } from "./audio.js";
export class ChessGame {
  constructor() {
    this.gameMode = "local";
    this.aiDifficulty = "easy";
    this.muteSounds = false;
    this.reset();
  }
  reset() {
    this.board = this.createInitialBoard();
    this.turn = "w";
    this.history = [];
    this.captured = { w: [], b: [] };
    this.kingPositions = { w: { r: 7, c: 4 }, b: { r: 0, c: 4 } };
    this.castlingRights = { w: { k: true, q: true }, b: { k: true, q: true } };
    this.activeSquare = null;
    this.validMoves = [];
    this.lastMove = null;
    this.isGameOver = false;
    this.promotionPending = null;
    this.lastCapturedPiece = null;
    this.lastMoveType = null;
    
    this.halfMoveClock = 0;
    this.positionHistory = {};
  }
  createInitialBoard() {
    const board = Array(8)
      .fill(null)
      .map(() => Array(8).fill(null));
    const majorBlack = ["r", "n", "b", "q", "k", "b", "n", "r"];
    for (let c = 0; c < 8; c++) {
      board[0][c] = { type: majorBlack[c], color: "b" };
      board[1][c] = { type: "p", color: "b" };
    }
    const majorWhite = ["r", "n", "b", "q", "k", "b", "n", "r"];
    for (let c = 0; c < 8; c++) {
      board[6][c] = { type: "p", color: "w" };
      board[7][c] = { type: majorWhite[c], color: "w" };
    }
    return board;
  }
  getPiece(r, c) {
    if (r < 0 || r >= 8 || c < 0 || c >= 8) return null;
    return this.board[r][c];
  }
  movePiece(fromR, fromC, toR, toC, simulate = false) {
    const piece = this.board[fromR][fromC];
    const target = this.board[toR][toC];
    if (piece.type === "k") {
      this.kingPositions[piece.color] = { r: toR, c: toC };
    }
    this.board[toR][toC] = piece;
    this.board[fromR][fromC] = null;
    return target;
  }
  undoMovePiece(fromR, fromC, toR, toC, originalPiece, originalTarget) {
    this.board[fromR][fromC] = originalPiece;
    this.board[toR][toC] = originalTarget;
    if (originalPiece.type === "k") {
      this.kingPositions[originalPiece.color] = { r: fromR, c: fromC };
    }
  }
  getAlgebraicNotation(fromR, fromC, toR, toC, piece, captured, isCastling) {
    if (isCastling === "k") return "O-O";
    if (isCastling === "q") return "O-O-O";
    const files = ["a", "b", "c", "d", "e", "f", "g", "h"];
    const ranks = ["8", "7", "6", "5", "4", "3", "2", "1"];
    let notation = "";
    if (piece.type !== "p") notation += piece.type.toUpperCase();
    else if (captured) notation += files[fromC];
    if (captured) notation += "x";
    notation += files[toC] + ranks[toR];
    return notation;
  }
  getBoardHash() {
    let s = "";
    for (let r = 0; r < 8; r++)
      for (let c = 0; c < 8; c++)
        s += this.board[r][c]
          ? this.board[r][c].type + this.board[r][c].color
          : "-";
    s +=
      this.turn +
      Object.values(this.castlingRights.w).join("") +
      Object.values(this.castlingRights.b).join("");
    return s;
  }
  isInsufficientMaterial() {
    let pieces = [];
    for (let r = 0; r < 8; r++)
      for (let c = 0; c < 8; c++)
        if (this.board[r][c]) pieces.push(this.board[r][c]);
    if (pieces.length === 2) return true; 
    if (pieces.length === 3) {
      const types = pieces.map((p) => p.type);
      if (types.includes("n") || types.includes("b")) return true; 
    }
    return false;
  }
  getPseudoMoves(r, c) {
    const piece = this.board[r][c];
    if (!piece) return [];
    const moves = [];
    const color = piece.color;
    const enemyColor = color === "w" ? "b" : "w";
    switch (piece.type) {
      case "p": {
        const dir = color === "w" ? -1 : 1;
        const startRank = color === "w" ? 6 : 1;
        if (r + dir >= 0 && r + dir < 8 && !this.board[r + dir][c]) {
          moves.push({ r: r + dir, c: c });
          if (r === startRank && !this.board[r + 2 * dir][c]) {
            moves.push({ r: r + 2 * dir, c: c });
          }
        }
        const capCols = [c - 1, c + 1];
        for (let col of capCols) {
          if (col >= 0 && col < 8 && r + dir >= 0 && r + dir < 8) {
            const target = this.board[r + dir][col];
            if (target && target.color === enemyColor)
              moves.push({ r: r + dir, c: col });
          }
        }
        if (this.history.length > 0) {
          const lastM = this.history[this.history.length - 1];
          if (
            lastM.piece === "p" &&
            lastM.color === enemyColor &&
            Math.abs(lastM.from.r - lastM.to.r) === 2
          ) {
            if (lastM.to.r === r && Math.abs(lastM.to.c - c) === 1) {
              moves.push({ r: r + dir, c: lastM.to.c, isEnPassant: true });
            }
          }
        }
        break;
      }
      case "r":
      case "b":
      case "q": {
        const directions = [];
        if (piece.type === "r" || piece.type === "q")
          directions.push([-1, 0], [1, 0], [0, -1], [0, 1]);
        if (piece.type === "b" || piece.type === "q")
          directions.push([-1, -1], [-1, 1], [1, -1], [1, 1]);
        for (let [dr, dc] of directions) {
          let currR = r + dr;
          let currC = c + dc;
          while (currR >= 0 && currR < 8 && currC >= 0 && currC < 8) {
            const target = this.board[currR][currC];
            if (!target) {
              moves.push({ r: currR, c: currC });
            } else {
              if (target.color === enemyColor)
                moves.push({ r: currR, c: currC });
              break;
            }
            currR += dr;
            currC += dc;
          }
        }
        break;
      }
      case "n": {
        const knightOffsets = [
          [-2, -1],
          [-2, 1],
          [-1, -2],
          [-1, 2],
          [1, -2],
          [1, 2],
          [2, -1],
          [2, 1],
        ];
        for (let [dr, dc] of knightOffsets) {
          const currR = r + dr;
          const currC = c + dc;
          if (currR >= 0 && currR < 8 && currC >= 0 && currC < 8) {
            const target = this.board[currR][currC];
            if (!target || target.color === enemyColor)
              moves.push({ r: currR, c: currC });
          }
        }
        break;
      }
      case "k": {
        const kingOffsets = [
          [-1, -1],
          [-1, 0],
          [-1, 1],
          [0, -1],
          [0, 1],
          [1, -1],
          [1, 0],
          [1, 1],
        ];
        for (let [dr, dc] of kingOffsets) {
          const currR = r + dr;
          const currC = c + dc;
          if (currR >= 0 && currR < 8 && currC >= 0 && currC < 8) {
            const target = this.board[currR][currC];
            if (!target || target.color === enemyColor)
              moves.push({ r: currR, c: currC });
          }
        }
        const rights = this.castlingRights[color];
        if (rights.k && !this.board[r][5] && !this.board[r][6]) {
          moves.push({ r: r, c: c + 2, isCastling: "k" });
        }
        if (
          rights.q &&
          !this.board[r][1] &&
          !this.board[r][2] &&
          !this.board[r][3]
        ) {
          moves.push({ r: r, c: c - 2, isCastling: "q" });
        }
        break;
      }
    }
    return moves;
  }

  isKingInCheck(color) {
    const kingPos = this.kingPositions[color];
    const enemyColor = color === "w" ? "b" : "w";
    for (let r = 0; r < 8; r++) {
      for (let c = 0; c < 8; c++) {
        const piece = this.board[r][c];
        if (piece && piece.color === enemyColor) {
          const pseudoMoves = this.getPseudoMoves(r, c);
          if (pseudoMoves.some((m) => m.r === kingPos.r && m.c === kingPos.c))
            return true;
        }
      }
    }
    return false;
  }
  getValidMoves(r, c) {
    const piece = this.board[r][c];
    if (!piece || piece.color !== this.turn) return [];
    const pseudoMoves = this.getPseudoMoves(r, c);
    const validMoves = [];
    for (let move of pseudoMoves) {
      const originalPiece = this.board[r][c];
      if (move.isCastling) {
        if (this.isKingInCheck(piece.color)) continue;
        let pathSafe = true;
        const dir = move.isCastling === "k" ? 1 : -1;
        const passC = c + dir;
        const savedKingPos = { ...this.kingPositions[piece.color] };
        const origTargetPass = this.movePiece(r, c, r, passC, true);
        if (this.isKingInCheck(piece.color)) pathSafe = false;
        this.undoMovePiece(r, c, r, passC, originalPiece, origTargetPass);
        this.kingPositions[piece.color] = savedKingPos;
        if (!pathSafe) continue;
      }
      let epCapturedPiece = null;
      if (move.isEnPassant) {
        epCapturedPiece = this.board[r][move.c];
        this.board[r][move.c] = null;
      }
      const originalTarget = this.movePiece(r, c, move.r, move.c, true);
      const inCheck = this.isKingInCheck(piece.color);
      if (move.isEnPassant) this.board[r][move.c] = epCapturedPiece;
      this.undoMovePiece(r, c, move.r, move.c, originalPiece, originalTarget);
      if (!inCheck) validMoves.push(move);
    }
    return validMoves;
  }
  hasAnyValidMoves(color) {
    for (let r = 0; r < 8; r++) {
      for (let c = 0; c < 8; c++) {
        const piece = this.board[r][c];
        if (piece && piece.color === color) {
          if (this.getValidMoves(r, c).length > 0) return true;
        }
      }
    }
    return false;
  }
  executeMove(fromR, fromC, toR, toC, promotedPieceType = null) {
    if (this.isGameOver) return false;
    const piece = this.board[fromR][fromC];
    if (!piece || piece.color !== this.turn) return false;
    const validMoves = this.getValidMoves(fromR, fromC);
    const moveObj = validMoves.find((m) => m.r === toR && m.c === toC);
    if (!moveObj) return false;
    const isPawn = piece.type === "p";
    const reachedEnd =
      (piece.color === "w" && toR === 0) || (piece.color === "b" && toR === 7);
    if (isPawn && reachedEnd && !promotedPieceType) {
      this.promotionPending = {
        from: { r: fromR, c: fromC },
        to: { r: toR, c: toC },
      };
      return "promotion";
    }
    let captured = this.board[toR][toC];
    if (moveObj.isEnPassant) {
      captured = this.board[fromR][toC];
      this.board[fromR][toC] = null;
    }
    this.lastCapturedPiece = captured
      ? { type: captured.type, color: captured.color, r: moveObj.isEnPassant ? fromR : toR, c: moveObj.isEnPassant ? toC : toC }
      : null;
    if (isPawn || captured) this.halfMoveClock = 0;
    else this.halfMoveClock++;
    this.movePiece(fromR, fromC, toR, toC);
    if (moveObj.isCastling === "k") {
      this.movePiece(fromR, 7, fromR, 5);
      this.lastMoveType = "castle";
    } else if (moveObj.isCastling === "q") {
      this.movePiece(fromR, 0, fromR, 3);
      this.lastMoveType = "castle";
    }
    if (piece.type === "k") {
      this.castlingRights[piece.color].k = false;
      this.castlingRights[piece.color].q = false;
    }
    if (piece.type === "r") {
      if (fromC === 0) this.castlingRights[piece.color].q = false;
      if (fromC === 7) this.castlingRights[piece.color].k = false;
    }
    if (captured && captured.type === "r") {
      if (toC === 0) this.castlingRights[captured.color].q = false;
      if (toC === 7) this.castlingRights[captured.color].k = false;
    }

    if (isPawn && reachedEnd && promotedPieceType) {
      this.board[toR][toC] = { type: promotedPieceType, color: piece.color };
      this.lastMoveType = "promotion";
    }
    if (captured) {
      this.captured[this.turn].push(captured.type);
    }
    if (!this.muteSounds) {
      if (captured) {
        this.lastMoveType = "capture";
        sounds.playCapture();
      } else if (moveObj.isCastling) {
        this.lastMoveType = "castle";
        sounds.playCastle();
      } else if (isPawn && reachedEnd && promotedPieceType) {
        this.lastMoveType = "promotion";
        sounds.playPromotion();
      } else {
        this.lastMoveType = "move";
        sounds.playMove();
      }
    }
    let notation = this.getAlgebraicNotation(
      fromR,
      fromC,
      toR,
      toC,
      piece,
      captured,
      moveObj.isCastling,
    );
    if (promotedPieceType) notation += "=" + promotedPieceType.toUpperCase();
    this.history.push({
      from: { r: fromR, c: fromC },
      to: { r: toR, c: toC },
      piece: piece.type,
      color: piece.color,
      captured: captured ? captured.type : null,
      promotedPieceType: promotedPieceType || null,
      algebraic: notation,
    });
    this.lastMove = { from: { r: fromR, c: fromC }, to: { r: toR, c: toC } };
    this.turn = this.turn === "w" ? "b" : "w";
    this.activeSquare = null;
    this.validMoves = [];
    const enemyColor = this.turn;
    const enemyInCheck = this.isKingInCheck(enemyColor);
    
    const noMovesLeft = !this.hasAnyValidMoves(enemyColor);
    if (enemyInCheck) {
      this.history[this.history.length - 1].algebraic += noMovesLeft
        ? "#"
        : "+";
      if (!this.muteSounds) sounds.playCheck();
    }
    if (noMovesLeft) {
      this.isGameOver = true;
      if (!this.muteSounds) sounds.playGameOver();
      return enemyInCheck
        ? { status: "checkmate", winner: enemyColor === "w" ? "b" : "w" }
        : { status: "stalemate" };
    }
    if (this.halfMoveClock >= 100) {
      this.isGameOver = true;
      return { status: "stalemate", reason: "50-move rule" };
    }
    if (this.isInsufficientMaterial()) {
      this.isGameOver = true;
      return { status: "stalemate", reason: "Insufficient material" };
    }
    const hash = this.getBoardHash();
    this.positionHistory[hash] = (this.positionHistory[hash] || 0) + 1;
    if (this.positionHistory[hash] >= 3) {
      this.isGameOver = true;
      return { status: "stalemate", reason: "Threefold repetition" };
    }
    return { status: "success", check: enemyInCheck };
  }    cloneState() {
    return {
      board: this.board.map((row) => row.map((p) => (p ? { ...p } : null))),
      kingPositions: JSON.parse(JSON.stringify(this.kingPositions)),
      castlingRights: JSON.parse(JSON.stringify(this.castlingRights)),
    };
  }
  restoreState(state) {
    this.board = state.board;
    this.kingPositions = state.kingPositions;
    this.castlingRights = state.castlingRights;
  }
  simulateRawMove(m) {
    const piece = this.board[m.from.r][m.from.c];
    if (m.to.isEnPassant) this.board[m.from.r][m.to.c] = null;
    if (m.to.isCastling === "k") this.movePiece(m.from.r, 7, m.from.r, 5, true);
    if (m.to.isCastling === "q") this.movePiece(m.from.r, 0, m.from.r, 3, true);
    this.movePiece(m.from.r, m.from.c, m.to.r, m.to.c, true);
    if (piece.type === "p" && (m.to.r === 0 || m.to.r === 7)) {
      this.board[m.to.r][m.to.c] = { type: "q", color: piece.color };
    }
  }
  evaluateBoard() {
    const pieceValues = { p: 100, n: 320, b: 330, r: 500, q: 900, k: 20000 };
    const PST = {
      p: [
        [0, 0, 0, 0, 0, 0, 0, 0],
        [50, 50, 50, 50, 50, 50, 50, 50],
        [10, 10, 20, 30, 30, 20, 10, 10],
        [5, 5, 10, 25, 25, 10, 5, 5],
        [0, 0, 0, 20, 20, 0, 0, 0],
        [5, -5, -10, 0, 0, -10, -5, 5],
        [5, 10, 10, -20, -20, 10, 10, 5],
        [0, 0, 0, 0, 0, 0, 0, 0],
      ],
      n: [
        [-50, -40, -30, -30, -30, -30, -40, -50],
        [-40, -20, 0, 0, 0, 0, -20, -40],
        [-30, 0, 10, 15, 15, 10, 0, -30],
        [-30, 5, 15, 20, 20, 15, 5, -30],
        [-30, 0, 15, 20, 20, 15, 0, -30],
        [-30, 5, 10, 15, 15, 10, 5, -30],
        [-40, -20, 0, 5, 5, 0, -20, -40],
        [-50, -40, -30, -30, -30, -30, -40, -50],
      ],
      b: [
        [-20, -10, -10, -10, -10, -10, -10, -20],
        [-10, 0, 0, 0, 0, 0, 0, -10],
        [-10, 0, 5, 10, 10, 5, 0, -10],
        [-10, 5, 5, 10, 10, 5, 5, -10],
        [-10, 0, 10, 10, 10, 10, 0, -10],
        [-10, 10, 10, 10, 10, 10, 10, -10],
        [-10, 5, 0, 0, 0, 0, 5, -10],
        [-20, -10, -10, -10, -10, -10, -10, -20],
      ],
      r: [
        [0, 0, 0, 0, 0, 0, 0, 0],
        [5, 10, 10, 10, 10, 10, 10, 5],
        [-5, 0, 0, 0, 0, 0, 0, -5],
        [-5, 0, 0, 0, 0, 0, 0, -5],
        [-5, 0, 0, 0, 0, 0, 0, -5],
        [-5, 0, 0, 0, 0, 0, 0, -5],
        [-5, 0, 0, 0, 0, 0, 0, -5],
        [0, 0, 0, 5, 5, 0, 0, 0],
      ],
      q: [
        [-20, -10, -10, -5, -5, -10, -10, -20],
        [-10, 0, 0, 0, 0, 0, 0, -10],
        [-10, 0, 5, 5, 5, 5, 0, -10],
        [-5, 0, 5, 5, 5, 5, 0, -5],
        [0, 0, 5, 5, 5, 5, 0, -5],
        [-10, 5, 5, 5, 5, 5, 0, -10],
        [-10, 0, 5, 0, 0, 0, 0, -10],
        [-20, -10, -10, -5, -5, -10, -10, -20],
      ],
      k: [
        [-30, -40, -40, -50, -50, -40, -40, -30],
        [-30, -40, -40, -50, -50, -40, -40, -30],
        [-30, -40, -40, -50, -50, -40, -40, -30],
        [-30, -40, -40, -50, -50, -40, -40, -30],
        [-20, -30, -30, -40, -40, -30, -30, -20],
        [-10, -20, -20, -20, -20, -20, -20, -10],
        [20, 20, 0, 0, 0, 0, 20, 20],
        [20, 30, 10, 0, 0, 10, 30, 20],
      ],
    };
    let score = 0;
    for (let r = 0; r < 8; r++) {
      for (let c = 0; c < 8; c++) {
        const piece = this.board[r][c];
        if (!piece) continue;
        const baseVal = pieceValues[piece.type];
        const pstRow = piece.color === "w" ? r : 7 - r;
        const pstVal = PST[piece.type] ? PST[piece.type][pstRow][c] : 0;
        const total = baseVal + pstVal;
        score += piece.color === "w" ? total : -total;
      }
    }
    return score;
  }
  getBestMove() {
    const color = "b";
    let possibleMoves = [];
    for (let r = 0; r < 8; r++) {
      for (let c = 0; c < 8; c++) {
        const piece = this.board[r][c];
        if (piece && piece.color === color) {
          const moves = this.getValidMoves(r, c);
          for (let m of moves) {
            let moveObj = { from: { r, c }, to: m, piece: piece };
            if (piece.type === "p" && (m.r === 0 || m.r === 7))
              moveObj.promotion = "q";
            possibleMoves.push(moveObj);
          }
        }
      }
    }
    if (possibleMoves.length === 0) return null;
    if (this.aiDifficulty === "easy")
      return possibleMoves[Math.floor(Math.random() * possibleMoves.length)];
    if (this.aiDifficulty === "medium") {
      const captures = possibleMoves.filter(
        (move) =>
          this.board[move.to.r][move.to.c] !== null || move.to.isEnPassant,
      );
      if (captures.length > 0) {
        const pVal = { p: 1, n: 3, b: 3, r: 5, q: 9, k: 100 };
        captures.sort((a, b) => {
    const pieceA = a.to.isEnPassant
            ? this.board[a.from.r][a.to.c]
            : this.board[a.to.r][a.to.c];
          const pieceB = b.to.isEnPassant
            ? this.board[b.from.r][b.to.c]
            : this.board[b.to.r][b.to.c];
          const valA = pVal[pieceA?.type] || 1;
          const valB = pVal[pieceB?.type] || 1;
          return valB - valA;
        });
        return captures[0];
      }
      return possibleMoves[Math.floor(Math.random() * possibleMoves.length)];
    }
    const minimax = (depth, alpha, beta, isMaximizing, currColor) => {
      if (depth === 0) return this.evaluateBoard();
      let moves = [];
      for (let r = 0; r < 8; r++) {
        for (let c = 0; c < 8; c++) {
          if (this.board[r][c] && this.board[r][c].color === currColor) {
            let vMoves = this.getValidMoves(r, c);
            for (let m of vMoves) moves.push({ from: { r, c }, to: m });
          }
        }
      }
    
    const mvvLva = { q: 9, r: 5, b: 3, n: 3, p: 1, k: 0 };
      moves.sort((a, b) => {
        const capA = this.board[a.to.r][a.to.c];
        const capB = this.board[b.to.r][b.to.c];
        return (mvvLva[capB?.type] || 0) - (mvvLva[capA?.type] || 0);
      });
      if (moves.length === 0)
        return this.isKingInCheck(currColor)
          ? isMaximizing
            ? -99999
            : 99999
          : 0;
      if (isMaximizing) {
        let maxEval = -Infinity;
        for (let m of moves) {
          const state = this.cloneState();
          this.simulateRawMove(m);
          let ev = minimax(
            depth - 1,
            alpha,
            beta,
            false,
            currColor === "w" ? "b" : "w",
          );
          this.restoreState(state);
          maxEval = Math.max(maxEval, ev);
          alpha = Math.max(alpha, ev);
          if (beta <= alpha) break;
        }
        return maxEval;
      } else {
        let minEval = Infinity;
        for (let m of moves) {
          const state = this.cloneState();
          this.simulateRawMove(m);
          let ev = minimax(
            depth - 1,
            alpha,
            beta,
            true,
            currColor === "w" ? "b" : "w",
          );
          this.restoreState(state);
          minEval = Math.min(minEval, ev);
          beta = Math.min(beta, ev);
          if (beta <= alpha) break;
        }
        return minEval;
      }
    };
    let bestMove = null;
    let bestBlackScore = Infinity;
    for (let m of possibleMoves) {
      const state = this.cloneState();
      this.simulateRawMove(m);
      let score = minimax(2, -Infinity, Infinity, true, "w");
      this.restoreState(state);
      if (score < bestBlackScore) {
        bestBlackScore = score;
        bestMove = m;
      }
    }
    return (
      bestMove ||
      possibleMoves[Math.floor(Math.random() * possibleMoves.length)]
    );
  }
}

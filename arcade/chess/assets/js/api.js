// Helpers auth: kirim token CSRF & tangani 401
function csrfToken() {
  return window.MEEL_CSRF || "";
}

function requireLogin() {

  window.location.href = "../../auth/login.php";
  return false;
}

async function guardedFetch(res) {
  if (res.status === 401) {
    requireLogin();
    return null;
  }
  return res;
}

export async function saveMoveAPI(roomCode, move) {
  const res = await fetch("controller/save_move.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      room: roomCode,
      fromR: move.from.r,
      fromC: move.from.c,
      toR: move.to.r,
      toC: move.to.c,
      piece: move.piece,
      color: move.color,
      captured: move.captured || null,
      promotedPieceType: move.promotedPieceType || null,
      csrf_token: csrfToken(),
    }),
  });
  if (!(await guardedFetch(res))) return { success: false };
  return await res.json();
}

export async function fetchMovesAPI(roomCode, afterId = 0) {
  const res = await fetch(
    `controller/get_move.php?room=${encodeURIComponent(roomCode)}&last=${afterId}`,
  );
  if (!(await guardedFetch(res))) return { moves: [], opponentOnline: true };
  const data = await res.json();

  if (Array.isArray(data)) return { moves: data, opponentOnline: true };
  return {
    moves: Array.isArray(data.moves) ? data.moves : [],
    opponentOnline:
      typeof data.opponent_online === "boolean" ? data.opponent_online : true,
  };
}

export async function checkRoomStatusAPI(roomCode) {
  const res = await fetch(
    `controller/check_room_status.php?room=${encodeURIComponent(roomCode)}`,
  );
  if (!(await guardedFetch(res))) return { success: false };
  return await res.json();
}

export async function createRoomAPI() {
  const form = new FormData();
  form.append("csrf_token", csrfToken());
  const res = await fetch("controller/create_room.php", {
    method: "POST",
    body: form,
  });
  if (!(await guardedFetch(res))) return { success: false };
  return await res.json();
}

export async function joinRoomAPI(code) {
  const form = new FormData();
  form.append("room", code.trim().toUpperCase());
  form.append("csrf_token", csrfToken());
  const res = await fetch("controller/join_room.php", {
    method: "POST",
    body: form,
  });
  if (!(await guardedFetch(res))) return { success: false };
  return await res.json();
}

export async function sendGameActionAPI(roomCode, action, extra = {}) {
  const form = new FormData();
  form.append("room", roomCode);
  form.append("action", action);
  // Field tambahan (mis. color + reason untuk event game_over).
  for (const [key, value] of Object.entries(extra)) form.append(key, value);
  form.append("csrf_token", csrfToken());
  const res = await fetch("controller/game_action.php", {
    method: "POST",
    body: form,
  });
  if (!(await guardedFetch(res))) return { success: false };
  return await res.json();
}

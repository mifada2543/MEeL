// ── Helpers auth: kirim token CSRF & tangani 401 ─────────────────────────
function csrfToken() {
  return window.MEEL_CSRF || "";
}

function requireLogin() {
  // Redirect ke halaman login (login.php tidak memakai param `next`)
  window.location.href = "../../auth/login.php";
  return false;
}

// Cek status HTTP; jika 401 (butuh login) arahkan ke halaman login.
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
  if (!(await guardedFetch(res))) return [];
  const data = await res.json();
  return Array.isArray(data) ? data : [];
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

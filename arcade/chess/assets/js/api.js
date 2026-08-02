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
    }),
  });
  return await res.json();
}
export async function fetchMovesAPI(roomCode, afterId = 0) {
  const res = await fetch(
    `controller/get_move.php?room=${encodeURIComponent(roomCode)}&last=${afterId}`,
  );
  const data = await res.json();
  return Array.isArray(data) ? data : [];
}
export async function checkRoomStatusAPI(roomCode) {
  const res = await fetch(`controller/check_room_status.php?room=${roomCode}`);
  return await res.json();
}
export async function createRoomAPI() {
  const res = await fetch("controller/create_room.php", { method: "POST" });
  return await res.json();
}
export async function joinRoomAPI(code) {
  const form = new FormData();
  form.append("room", code.trim().toUpperCase());
  const res = await fetch("controller/join_room.php", {
    method: "POST",
    body: form,
  });
  return await res.json();
}

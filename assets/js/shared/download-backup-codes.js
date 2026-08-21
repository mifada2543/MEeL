/* download-backup-codes.js — Download backup codes MFA sebagai file TXT.
 * Data disiapkan oleh halaman pemanggil:
 * window._meelBackupCodes = [kode...];  window._meelBackupUser = 'username';
 */
function downloadBackupCodes() {
  var codes = window._meelBackupCodes || [];
  if (!codes.length) return;
  var username = window._meelBackupUser || 'user';
  var dateStr = new Date().toISOString().replace(/T/, ' ').slice(0, 19);
  var lines = [
    'MEeL — MFA Backup Codes',
    'User: ' + username,
    'Generated: ' + dateStr,
    '',
    'Setiap kode hanya bisa digunakan SEKALI.',
    'Simpan di tempat yang aman!',
    ''
  ];
  codes.forEach(function (c) {
    lines.push('  ' + c);
  });

  var blob = new Blob([lines.join('\n') + '\n'], { type: 'text/plain;charset=utf-8' });
  var link = document.createElement('a');
  link.download = 'MEeL-backup-codes-' + username + '.txt';
  link.href = URL.createObjectURL(blob);
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(link.href);
}

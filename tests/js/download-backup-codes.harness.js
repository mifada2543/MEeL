// Test harness — menjalankan file JS shared di lingkungan browser mock
// (window/document/Blob/URL), lalu mencetak JSON hasil observasi untuk
// ditegaskan oleh tests/unit/SharedJsTest.php.
//
// Pemakaian: node tests/js/download-backup-codes.harness.js [scenario]
//   normal (default) | empty | unset | noUser | keys
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const scenario = process.argv[2] || 'normal';
const root     = path.join(__dirname, '..', '..');

const captured = {
  clicked: 0,
  appended: 0,
  removed: 0,
  blobCreated: false,
  blobParts: null,
  blobType: null,
  downloadName: null,
  href: null
};

class MockBlob {
  constructor(parts, opts) {
    captured.blobCreated = true;
    captured.blobParts = parts;
    captured.blobType = opts && opts.type;
  }
}

const sandbox = {
  console: console,
  window: {},
  document: {
    createElement(tag) {
      return {
        tag: tag,
        download: '',
        href: '',
        click() { captured.clicked++; }
      };
    },
    body: {
      appendChild(el) {
        captured.appended++;
        captured.downloadName = el.download;
        captured.href = el.href;
      },
      removeChild() { captured.removed++; }
    }
  },
  URL: {
    createObjectURL() { return 'blob:mock'; },
    revokeObjectURL() {}
  },
  Blob: MockBlob
};
sandbox.window = sandbox;

vm.createContext(sandbox);

if (scenario === 'keys') {
  // Jalankan state-keys.js dan verifikasi nilai + freeze secara runtime.
  const keysFile = path.join(root, 'assets', 'js', 'shared', 'state-keys.js');
  vm.runInContext(fs.readFileSync(keysFile, 'utf8'), sandbox, { filename: 'state-keys.js' });

  const keys = sandbox.window.MEEL_KEYS;
  const before = keys.AUDIO_STATE;
  let threw = false;
  try {
    keys.AUDIO_STATE = 'hacked';
  } catch (e) {
    threw = true;
  }
  const mutationBlocked = threw || keys.AUDIO_STATE === before;

  console.log(JSON.stringify({
    keys: Object.assign({}, keys),
    frozen: Object.isFrozen(keys),
    mutationBlocked: mutationBlocked
  }));
  process.exit(0);
}

const file = path.join(root, 'assets', 'js', 'shared', 'download-backup-codes.js');
vm.runInContext(fs.readFileSync(file, 'utf8'), sandbox, { filename: 'download-backup-codes.js' });

if (scenario === 'normal') {
  sandbox.window._meelBackupCodes = ['111111', '222222'];
  sandbox.window._meelBackupUser = 'alice';
} else if (scenario === 'noUser') {
  sandbox.window._meelBackupCodes = ['111111'];
  // _meelBackupUser tidak di-set -> fallback 'user'
} else if (scenario === 'empty') {
  sandbox.window._meelBackupCodes = [];
} // 'unset' -> _meelBackupCodes tidak pernah di-set

sandbox.downloadBackupCodes();

console.log(JSON.stringify(captured));

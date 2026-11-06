import assert from 'node:assert/strict';
import {existsSync,readFileSync} from 'node:fs';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';

const root = fileURLToPath(new URL('../', import.meta.url));
const file = name => readFileSync(join(root, name), 'utf8');
const login = file('index.php');
const shell = file('v7-index.html');
const css = file('v7-login.css');

assert.equal(existsSync(join(root, 'dashboard-v5.php')), false);
assert.equal(existsSync(join(root, 'bridge-v5.php')), false);
assert.equal(existsSync(join(root, 'index.php.bak_20261104-162014')), false);
assert.equal(existsSync(join(root, 'assets')), false);
assert.equal(existsSync(join(root, 'v5-assets')), false);
assert.match(login, /require_once __DIR__ \. '\/auth\.php'/);
assert.match(login, /Location: dashboard-v7\.php/);
assert.match(login, /rw_csrf_token\(\)/);
assert.match(login, /\$mode === 'changepw'/);
assert.doesNotMatch(login, /pane-overview|tab-accounts|rw-tabs/);
assert.doesNotMatch(shell, /dashboard-v5\.php|Quản lý V5/);
assert.match(css, /\.rw-login \.rw-btn/);
console.log('PASS: V7 login/dashboard remain; legacy dashboard, bridge, backup and links removed');

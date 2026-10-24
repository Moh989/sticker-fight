import assert from 'node:assert/strict';
import {createDraftStorage} from '../resources/js/shared/draft-storage.mjs';

const entries = new Map();
const storage = {getItem: key => entries.get(key) ?? null, setItem: (key, value) => entries.set(key, value)};
const first = 'a'.repeat(64), second = 'b'.repeat(64);
const a = createDraftStorage(first, () => storage), b = createDraftStorage(second, () => storage);
const id = '11111111-1111-4111-8111-111111111111';
const draft = {id, revision: 0, name: 'زائر أول · PRIVATE A', document: {version: 1, sides: {front: {elements: [{type: 'text', text: 'PRIVATE A'}]}, back: {elements: []}}}, options: {mode: 'single'}};

assert.equal(a.write(draft), true);
assert.equal(a.latest().name, draft.name);
assert.equal(b.latest(), null);
assert.equal(b.read(id), null);
assert.equal(b.write({...draft, name: 'PRIVATE B'}), true);
assert.equal(a.read(id).name, draft.name);
assert.equal(b.read(id).name, 'PRIVATE B');

entries.set('sticker-fight:last-draft', id);
entries.set('sticker-fight:design:' + id, JSON.stringify(draft));
assert.equal(createDraftStorage('c'.repeat(64), () => storage).latest(), null);
assert.ok(entries.has('sticker-fight:design:' + id));

const key = `sticker-fight:workspace:${second}:design:${id}`;
entries.set(key, JSON.stringify(a.read(id)));
assert.equal(b.read(id), null);
b.write({...draft, name: 'PRIVATE B'});
entries.set(key, JSON.stringify({...b.read(id), updatedAt: Date.now() - 8 * 86400000}));
assert.equal(b.latest(), null);
entries.set(key, 'invalid JSON');
assert.equal(b.latest(), null);
const blocked = createDraftStorage(first, () => { throw new Error('Storage blocked'); });
assert.equal(blocked.latest(), null);
assert.equal(blocked.write(draft), false);
assert.equal(createDraftStorage(null, () => storage).write(draft), false);
console.log(JSON.stringify({passed: true,checks: 10,guestIsolation: true,legacyIgnoredWithoutDeletion: true,blockedStorageHandled: true}));

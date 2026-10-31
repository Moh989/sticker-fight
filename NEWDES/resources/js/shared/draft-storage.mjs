const validId = value => typeof value === 'string' && /^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i.test(value);

export function createDraftStorage(workspace, getStorage = () => localStorage) {
    const enabled = typeof workspace === 'string' && /^[0-9a-f]{64}$/.test(workspace);
    const prefix = `sticker-fight:workspace:${workspace}:`;
    const lastKey = prefix + 'last-draft';
    const key = id => prefix + 'design:' + id;

    function read(id) {
        if (!enabled || !validId(id)) return null;
        try {
            const draft = JSON.parse(getStorage().getItem(key(id)) || 'null');
            if (!draft || draft.workspace !== workspace || draft.id !== id
                || !Number.isFinite(draft.updatedAt) || draft.updatedAt < Date.now() - 7 * 86400000 || draft.updatedAt > Date.now() + 60000
                || !Number.isInteger(draft.revision) || draft.revision < 0
                || typeof draft.name !== 'string' || !['single', 'double', 'mixed'].includes(draft.options?.mode)
                || !Array.isArray(draft.document?.sides?.front?.elements) || !Array.isArray(draft.document?.sides?.back?.elements)) return null;
            return draft;
        } catch {
            return null;
        }
    }

    return {
        read,
        latest() {
            if (!enabled) return null;
            try { return read(getStorage().getItem(lastKey)); } catch { return null; }
        },
        write(draft) {
            if (!enabled || !validId(draft.id)) return false;
            try {
                const storage = getStorage();
                storage.setItem(key(draft.id), JSON.stringify({...draft, workspace, updatedAt: Date.now()}));
                storage.setItem(lastKey, draft.id);
                return true;
            } catch {
                return false;
            }
        },
    };
}

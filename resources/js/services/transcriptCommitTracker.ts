interface PendingCommit {
    frames: string[];
    itemId: string | null;
}

export class TranscriptCommitTracker {
    private pending: PendingCommit[] = [];
    private emptyWaiters = new Set<() => void>();

    enqueue(frames: string[]) {
        this.pending.push({ frames, itemId: null });
    }

    acknowledge(itemId: string): boolean {
        const commit = this.pending.find((item) => item.itemId === null);
        if (!commit) return false;
        commit.itemId = itemId;
        return true;
    }

    complete(itemId: string): boolean {
        const index = this.pending.findIndex((item) => item.itemId === itemId);
        if (index < 0) return false;
        this.pending.splice(index, 1);
        this.resolveIfEmpty();
        return true;
    }

    takeFramesForReplay(): string[] {
        const frames = this.pending.flatMap((item) => item.frames);
        this.pending = [];
        return frames;
    }

    waitUntilEmpty(timeoutMs: number): Promise<boolean> {
        if (this.pending.length === 0) return Promise.resolve(true);

        return new Promise((resolve) => {
            const finish = () => {
                globalThis.clearTimeout(timeout);
                this.emptyWaiters.delete(finish);
                resolve(true);
            };
            const timeout = globalThis.setTimeout(() => {
                this.emptyWaiters.delete(finish);
                resolve(false);
            }, timeoutMs);
            this.emptyWaiters.add(finish);
        });
    }

    reset() {
        this.pending = [];
        this.emptyWaiters.forEach((resolve) => resolve());
        this.emptyWaiters.clear();
    }

    get size() {
        return this.pending.length;
    }

    private resolveIfEmpty() {
        if (this.pending.length > 0) return;
        this.emptyWaiters.forEach((resolve) => resolve());
        this.emptyWaiters.clear();
    }
}

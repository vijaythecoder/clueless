export class ReliableBatchQueue<T> {
    private items: T[] = [];
    private flushing: Promise<void> | null = null;

    constructor(private readonly send: (items: T[]) => Promise<void>) {}

    get size() {
        return this.items.length;
    }

    push(item: T) {
        this.items.push(item);
    }

    flush(): Promise<void> {
        if (!this.flushing) {
            this.flushing = this.drain().finally(() => {
                this.flushing = null;
            });
        }

        return this.flushing;
    }

    private async drain() {
        while (this.items.length > 0) {
            const batch = this.items.slice();
            await this.send(batch);
            this.items.splice(0, batch.length);
        }
    }
}

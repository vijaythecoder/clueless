import { ReliableBatchQueue } from '@/services/reliableQueue';
import { describe, expect, it, vi } from 'vitest';

describe('ReliableBatchQueue', () => {
    it('keeps items queued when sending fails and retries them', async () => {
        const send = vi.fn<(items: string[]) => Promise<void>>().mockRejectedValueOnce(new Error('offline')).mockResolvedValueOnce();
        const queue = new ReliableBatchQueue(send);

        queue.push('first');
        await expect(queue.flush()).rejects.toThrow('offline');
        expect(queue.size).toBe(1);

        await queue.flush();
        expect(queue.size).toBe(0);
        expect(send).toHaveBeenNthCalledWith(1, ['first']);
        expect(send).toHaveBeenNthCalledWith(2, ['first']);
    });

    it('coalesces overlapping flushes without dropping later items', async () => {
        let releaseFirst!: () => void;
        const send = vi
            .fn<(items: string[]) => Promise<void>>()
            .mockImplementationOnce(() => new Promise<void>((resolve) => (releaseFirst = resolve)))
            .mockResolvedValueOnce();
        const queue = new ReliableBatchQueue(send);

        queue.push('first');
        const firstFlush = queue.flush();
        queue.push('second');
        const secondFlush = queue.flush();
        releaseFirst();

        await Promise.all([firstFlush, secondFlush]);
        expect(send).toHaveBeenNthCalledWith(1, ['first']);
        expect(send).toHaveBeenNthCalledWith(2, ['second']);
        expect(queue.size).toBe(0);
    });
});
